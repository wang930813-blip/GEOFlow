#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Created by Codex.
@Date: 2026-10-28
@Time: 17:59:50
@Author: cdkay
@Email: network@iyuanma.net

@File：server.py
@Description: 通过 Unix Socket 安全管理客户域名 Nginx 代理及 HTTPS 证书。
"""

from __future__ import print_function

import json
import ipaddress
import logging
import os
import re
import socket
import socketserver
import stat
import subprocess
import tempfile
from typing import Any, Callable, Dict, Optional, Tuple


MAX_REQUEST_BYTES = 1048576
MAX_PEM_BYTES = 262144
DEFAULT_SOCKET_PATH = "/run/geoflow-domain-deployer/deployer.sock"
DEFAULT_NGINX_BINARY = "/www/server/nginx/sbin/nginx"
DEFAULT_NGINX_CONFIG = "/www/server/nginx/conf/nginx.conf"
DEFAULT_SITE_CONFIG_DIR = "/www/server/nginx/conf/geoflow-customer-sites"
DEFAULT_CERTIFICATE_DIR = "/www/server/nginx/conf/geoflow-customer-certs"
DEFAULT_BACKEND = "http://127.0.0.1:18080"


class DomainDeployer(object):
    """为固定目录内的站点文件执行校验、原子更新和 Nginx reload。"""

    def __init__(
        self,
        nginx_binary: str = DEFAULT_NGINX_BINARY,
        nginx_config: str = DEFAULT_NGINX_CONFIG,
        site_config_dir: str = DEFAULT_SITE_CONFIG_DIR,
        certificate_dir: str = DEFAULT_CERTIFICATE_DIR,
        backend: str = DEFAULT_BACKEND,
        nginx_runner: Optional[Callable[[Tuple[str, ...]], None]] = None,
        nginx_dump: Optional[Callable[[], str]] = None,
    ) -> None:
        """初始化部署路径和可注入的 Nginx 执行器。

        @Name: __init__
        @Description: 固定受控文件目录并支持隔离测试替换 Nginx 命令执行器。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param str nginx_binary Nginx 可执行文件路径
        @Param str nginx_config Nginx 主配置文件路径
        @Param str site_config_dir 受控站点配置目录
        @Param str certificate_dir 受控证书目录
        @Param str backend 本机 GEOFlow HTTP 入口
        @Return None 完成初始化
        @Throws OSError 受控目录无法创建时抛出
        """
        self.nginx_binary = nginx_binary
        self.nginx_config = nginx_config
        self.site_config_dir = os.path.realpath(site_config_dir)
        self.certificate_dir = os.path.realpath(certificate_dir)
        self.backend = backend
        self.nginx_runner = nginx_runner or self._run_nginx
        self.nginx_dump = nginx_dump or self._dump_nginx
        os.makedirs(self.site_config_dir, mode=0o755, exist_ok=True)
        os.makedirs(self.certificate_dir, mode=0o700, exist_ok=True)

    def synchronize(self, request: Dict[str, Any]) -> None:
        """校验域名、生成专用配置并在 Nginx 检查通过后 reload。

        @Name: synchronize
        @Description: 将域名及可选 TLS 文件写入受控目录，失败时恢复快照。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param Dict[str, Any] request Laravel 发送的站点配置
        @Return None 部署成功后返回
        @Throws ValueError 请求字段或域名不合法时抛出
        @Throws RuntimeError 域名冲突、Nginx 校验或 reload 失败时抛出
        """
        site_id = self._site_id(request)
        domain = self._normalize_domain(request.get("domain"))
        https_enabled = request.get("https_enabled")
        if type(https_enabled) is not bool:
            raise ValueError("https_enabled must be boolean")

        certificate = self._pem(request.get("certificate", ""), "certificate")
        private_key = self._pem(request.get("private_key", ""), "private_key")
        if https_enabled and (not certificate or not private_key):
            raise ValueError("HTTPS requires a certificate and private key")
        if not https_enabled and (certificate or private_key):
            raise ValueError("HTTP-only deployments must not contain certificate material")

        config_path = self._site_config_path(site_id)
        certificate_path = self._certificate_path(site_id)
        private_key_path = self._private_key_path(site_id)
        self._ensure_domain_available(domain, config_path)

        paths = (config_path, certificate_path, private_key_path)
        snapshot = self._snapshot(paths)
        try:
            if https_enabled:
                self._atomic_write(certificate_path, certificate.encode("utf-8"), 0o644)
                self._atomic_write(private_key_path, private_key.encode("utf-8"), 0o600)
            else:
                self._remove_file(certificate_path)
                self._remove_file(private_key_path)

            self._atomic_write(
                config_path,
                self._render_config(domain, https_enabled, certificate_path, private_key_path).encode("utf-8"),
                0o644,
            )
            self._apply_nginx_change()
        except Exception as exception:
            try:
                self._restore(snapshot)
            except Exception as rollback_exception:
                logging.exception("Customer domain file rollback failed for site %s", site_id)
                raise RuntimeError("Nginx deployment rollback failed and requires administrator review.") from rollback_exception
            nginx_rollback_confirmed = self._restore_nginx_if_needed()
            logging.exception("Customer domain deployment failed for site %s", site_id)
            message = (
                "Nginx configuration failed; the previous site configuration was restored."
                if nginx_rollback_confirmed
                else "Nginx configuration failed; restored files require administrator verification."
            )
            raise RuntimeError(message) from exception

    def remove(self, request: Dict[str, Any]) -> None:
        """移除指定站点的专用代理配置和证书并应用 Nginx 变更。

        @Name: remove
        @Description: 只删除站点编号对应的专用配置文件并在验证后 reload。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param Dict[str, Any] request Laravel 发送的站点清理请求
        @Return None 清理成功后返回
        @Throws ValueError 站点编号不合法时抛出
        @Throws RuntimeError Nginx 校验或 reload 失败时抛出
        """
        site_id = self._site_id(request)
        paths = (
            self._site_config_path(site_id),
            self._certificate_path(site_id),
            self._private_key_path(site_id),
        )
        if not any(os.path.exists(path) for path in paths):
            return

        snapshot = self._snapshot(paths)
        try:
            for path in paths:
                self._remove_file(path)
            self._apply_nginx_change()
        except Exception as exception:
            try:
                self._restore(snapshot)
            except Exception as rollback_exception:
                logging.exception("Customer domain cleanup rollback failed for site %s", site_id)
                raise RuntimeError("Nginx cleanup rollback failed and requires administrator review.") from rollback_exception
            nginx_rollback_confirmed = self._restore_nginx_if_needed()
            logging.exception("Customer domain cleanup failed for site %s", site_id)
            message = (
                "Nginx configuration failed; the previous site configuration was restored."
                if nginx_rollback_confirmed
                else "Nginx configuration failed; restored files require administrator verification."
            )
            raise RuntimeError(message) from exception

    def _site_id(self, request: Dict[str, Any]) -> int:
        """验证站点编号，确保它只能成为受控文件名的一部分。

        @Name: _site_id
        @Description: 拒绝非整数、布尔值和非正数，防止文件路径注入。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param Dict[str, Any] request 已解析的部署请求
        @Return int 正整数站点编号
        @Throws ValueError 编号缺失、类型错误或不是正整数时抛出
        """
        site_id = request.get("site_id")
        if type(site_id) is not int or site_id < 1:
            raise ValueError("site_id must be a positive integer")

        return site_id

    def _normalize_domain(self, value: Any) -> str:
        """仅接受公开 DNS 主机名并统一为小写 ASCII 域名。

        @Name: _normalize_domain
        @Description: 先进行 IDNA 转换，再按 DNS 标签规则拒绝 Nginx 配置字符。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:59:50

        @Param Any value 请求中的域名值
        @Return str 规范化后的 FQDN
        @Throws ValueError 域名包含路径、端口、IP 或 Nginx 特殊字符时抛出
        """
        if not isinstance(value, str):
            raise ValueError("domain must be a string")

        try:
            domain = value.strip().rstrip(".").encode("idna").decode("ascii").lower()
        except UnicodeError as exception:
            raise ValueError("domain is invalid") from exception

        try:
            ipaddress.ip_address(domain)
        except ValueError:
            pass
        else:
            raise ValueError("domain must be a DNS host name")

        if len(domain) > 253 or "." not in domain:
            raise ValueError("domain must be a fully qualified host name")

        labels = domain.split(".")
        label_pattern = re.compile(r"^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$")
        if any(not label_pattern.match(label) for label in labels):
            raise ValueError("domain contains an invalid label")

        return domain

    def _pem(self, value: Any, name: str) -> str:
        """限制证书与私钥为大小受控的 PEM 文本。

        @Name: _pem
        @Description: 限制载荷字段类型和字节数，避免超长凭据占用资源。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param Any value 请求中的 PEM 字符串
        @Param str name 字段名
        @Return str 去除首尾空白后的 PEM 文本
        @Throws ValueError 字段类型或内容大小不符合要求时抛出
        """
        if not isinstance(value, str):
            raise ValueError(name + " must be a string")
        if len(value.encode("utf-8")) > MAX_PEM_BYTES:
            raise ValueError(name + " exceeds the permitted size")

        return value.strip()

    def _ensure_domain_available(self, domain: str, current_config: str) -> None:
        """扫描 Nginx 展开配置并拒绝已被其他站点声明的主机名。

        @Name: _ensure_domain_available
        @Description: 解析 Nginx -T 文件边界及多行 server_name 指令并排除本站配置。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:59:50

        @Param str domain 待部署主机名
        @Param str current_config 当前站点受控配置路径
        @Return None 域名可用时返回
        @Throws RuntimeError 域名已由其他 Nginx 配置占用时抛出
        """
        current_config = os.path.realpath(current_config)
        current_source = ""
        pending_directive = None
        for line in self.nginx_dump().splitlines():
            if line.startswith("# configuration file ") and line.endswith(":"):
                current_source = os.path.realpath(line[len("# configuration file "):-1])
                pending_directive = None
                continue
            if current_source == current_config:
                pending_directive = None
                continue

            directive = line.split("#", 1)[0].strip()
            if pending_directive is not None:
                pending_directive += " " + directive
            elif directive.lower() == "server_name":
                pending_directive = ""
            elif directive.lower().startswith("server_name "):
                pending_directive = directive[len("server_name "):]
            else:
                continue

            if pending_directive is None or ";" not in pending_directive:
                continue
            declared_names = pending_directive.split(";", 1)[0].split()
            pending_directive = None
            if any(self._server_name_matches(name.lower(), domain) for name in declared_names):
                raise RuntimeError("domain is already declared by another Nginx virtual host")

    def _server_name_matches(self, server_name: str, domain: str) -> bool:
        """比对精确域名及 Nginx 左侧通配域名占用情况。

        @Name: _server_name_matches
        @Description: 精确比对主机名并覆盖 Nginx 左侧通配规则。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param str server_name Nginx 中已声明的主机名
        @Param str domain 待部署的规范化域名
        @Return bool 当前声明会与待部署域名竞争时为真
        @Throws None 无
        """
        server_name = server_name.strip("\"'")
        if server_name == domain:
            return True
        if server_name.startswith("*."):
            suffix = server_name[1:]

            return domain.endswith(suffix) and domain != server_name[2:]

        return False

    def _render_config(self, domain: str, https_enabled: bool, certificate_path: str, private_key_path: str) -> str:
        """生成不含用户可控配置片段的固定反向代理模板。

        @Name: _render_config
        @Description: 生成 64 MB 请求体限制、代理头及可选 TLS 虚拟主机。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param str domain 已规范化的客户域名
        @Param bool https_enabled 是否部署 TLS
        @Param str certificate_path 受控证书路径
        @Param str private_key_path 受控私钥路径
        @Return str 完整 Nginx server 配置
        @Throws None 无
        """
        http_action = "return 308 https://$host$request_uri;" if https_enabled else "proxy_pass " + self.backend + ";"
        config = [
            "server {",
            "    listen 80;",
            "    server_name " + domain + ";",
            "    client_max_body_size 64m;",
            "    location / {",
        ]
        if https_enabled:
            config.append("        " + http_action)
        else:
            config.extend(self._proxy_directives())
        config.extend(["    }", "}"])

        if https_enabled:
            config.extend([
                "server {",
                "    listen 443 ssl;",
                "    server_name " + domain + ";",
                "    client_max_body_size 64m;",
                "    ssl_certificate " + certificate_path + ";",
                "    ssl_certificate_key " + private_key_path + ";",
                "    ssl_protocols TLSv1.2 TLSv1.3;",
                "    location / {",
            ])
            config.extend(self._proxy_directives())
            config.extend(["    }", "}"])

        return "\n".join(config) + "\n"

    def _proxy_directives(self) -> Tuple[str, ...]:
        """生成固定的反向代理头和上游地址。

        @Name: _proxy_directives
        @Description: 透传原始 Host、真实 IP、转发协议并支持 WebSocket。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Return Tuple[str, ...] Nginx location 指令
        @Throws None 无
        """
        return (
            "        proxy_pass " + self.backend + ";",
            "        proxy_http_version 1.1;",
            "        proxy_set_header Host $host;",
            "        proxy_set_header X-Real-IP $remote_addr;",
            "        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;",
            "        proxy_set_header X-Forwarded-Host $host;",
            "        proxy_set_header X-Forwarded-Proto $scheme;",
            "        proxy_set_header Upgrade $http_upgrade;",
            '        proxy_set_header Connection "upgrade";',
            "        proxy_read_timeout 300;",
            "        proxy_send_timeout 300;",
            "        proxy_redirect off;",
        )

    def _apply_nginx_change(self) -> None:
        """在 reload 前执行 Nginx 全量语法及证书检查。

        @Name: _apply_nginx_change
        @Description: 只有完整配置测试通过后才通知 Nginx reload。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Return None 检查与 reload 均成功时返回
        @Throws RuntimeError Nginx 检查或 reload 非零退出时抛出
        """
        self.nginx_runner(("-t",))
        self.nginx_runner(("-s", "reload"))

    def _snapshot(self, paths: Tuple[str, ...]) -> Dict[str, Optional[Tuple[bytes, int]]]:
        """保存本次部署会修改的专用文件原始内容和权限。

        @Name: _snapshot
        @Description: 仅接受受控目录内的普通文件并保留原权限用于回滚。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param Tuple[str, ...] paths 受控文件路径
        @Return Dict[str, Optional[Tuple[bytes, int]]] 路径对应的原始状态
        @Throws OSError 文件存在但无法安全读取时抛出
        """
        snapshot = {}
        for path in paths:
            if not os.path.exists(path):
                snapshot[path] = None
                continue
            file_stat = os.stat(path)
            if not stat.S_ISREG(file_stat.st_mode):
                raise RuntimeError("managed deployment path is not a regular file")
            with open(path, "rb") as managed_file:
                snapshot[path] = (managed_file.read(), stat.S_IMODE(file_stat.st_mode))

        return snapshot

    def _restore(self, snapshot: Dict[str, Optional[Tuple[bytes, int]]]) -> None:
        """恢复文件快照或移除本次新建的文件。

        @Name: _restore
        @Description: 按快照原子写回旧文件并删除本次新建文件。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param Dict[str, Optional[Tuple[bytes, int]]] 先前保存的文件状态
        @Return None 恢复完成
        @Throws OSError 文件系统拒绝恢复时抛出
        """
        for path, previous in snapshot.items():
            if previous is None:
                self._remove_file(path)
            else:
                self._atomic_write(path, previous[0], previous[1])

    def _restore_nginx_if_needed(self) -> bool:
        """尽力校验并重新加载已恢复的上一版配置。

        @Name: _restore_nginx_if_needed
        @Description: 回滚后仅在旧配置再次通过测试时 reload，并报告恢复是否确认。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Return bool 回滚配置测试及 reload 均成功时为真
        @Throws None 恢复阶段异常会记录日志并返回失败
        """
        try:
            self.nginx_runner(("-t",))
            self.nginx_runner(("-s", "reload"))
            return True
        except Exception:
            logging.exception("Nginx could not reload the restored customer domain configuration")
            return False

    def _site_config_path(self, site_id: int) -> str:
        """返回按站点编号生成的固定配置文件路径。

        @Name: _site_config_path
        @Description: 使用已校验的整数站点编号生成固定路径。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param int site_id 已校验站点编号
        @Return str 站点配置路径
        @Throws None 无
        """
        return os.path.join(self.site_config_dir, str(site_id) + ".conf")

    def _certificate_path(self, site_id: int) -> str:
        """返回按站点编号生成的固定证书路径。

        @Name: _certificate_path
        @Description: 生成受控目录下的证书路径，不使用请求中的文件名。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param int site_id 已校验站点编号
        @Return str 证书路径
        @Throws None 无
        """
        return os.path.join(self.certificate_dir, str(site_id) + ".crt")

    def _private_key_path(self, site_id: int) -> str:
        """返回按站点编号生成的固定私钥路径。

        @Name: _private_key_path
        @Description: 生成受控目录下的私钥路径，不使用请求中的文件名。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param int site_id 已校验站点编号
        @Return str 私钥路径
        @Throws None 无
        """
        return os.path.join(self.certificate_dir, str(site_id) + ".key")

    def _atomic_write(self, path: str, content: bytes, mode: int) -> None:
        """在目标目录内写入临时文件并原子替换受控文件。

        @Name: _atomic_write
        @Description: fsync 临时文件后原子替换，避免 Nginx 读到半写入证书。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param str path 受控目标路径
        @Param bytes content 文件内容
        @Param int mode 目标权限
        @Return None 写入完成
        @Throws OSError 写入或替换失败时抛出
        """
        descriptor, temporary_path = tempfile.mkstemp(prefix=".geoflow-", dir=os.path.dirname(path))
        try:
            with os.fdopen(descriptor, "wb") as temporary_file:
                temporary_file.write(content)
                temporary_file.flush()
                os.fsync(temporary_file.fileno())
            os.chmod(temporary_path, mode)
            os.replace(temporary_path, path)
        finally:
            if os.path.exists(temporary_path):
                os.unlink(temporary_path)

    def _remove_file(self, path: str) -> None:
        """仅删除部署服务专用目录中的普通文件。

        @Name: _remove_file
        @Description: 通过 lstat 拒绝符号链接和目录后再删除指定文件。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param str path 已由站点编号构造的受控路径
        @Return None 文件不存在或删除完成
        @Throws RuntimeError 路径不是普通文件时抛出
        @Throws OSError 删除失败时抛出
        """
        if not os.path.lexists(path):
            return
        if not stat.S_ISREG(os.lstat(path).st_mode):
            raise RuntimeError("managed deployment path is not a regular file")
        os.unlink(path)

    def _run_nginx(self, arguments: Tuple[str, ...]) -> None:
        """执行固定二进制路径的 Nginx 命令并记录完整诊断输出。

        @Name: _run_nginx
        @Description: 仅执行预定义的校验或 reload 参数，不拼接用户输入。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Param Tuple[str, ...] arguments 固定参数元组
        @Return None 命令成功时返回
        @Throws RuntimeError 命令失败时抛出
        """
        command = [self.nginx_binary]
        if self.nginx_config:
            command.extend(("-c", self.nginx_config))
        command.extend(arguments)
        try:
            subprocess.check_output(command, stderr=subprocess.STDOUT)
        except subprocess.CalledProcessError as exception:
            logging.error("Nginx command failed: %s", exception.output.decode("utf-8", "replace"))
            raise RuntimeError("nginx command failed") from exception

    def _dump_nginx(self) -> str:
        """读取 Nginx 展开后的虚拟主机配置用于域名冲突检查。

        @Name: _dump_nginx
        @Description: 通过 Nginx -T 读取有效配置，并在失败时阻止部署。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Return str Nginx -T 输出
        @Throws RuntimeError Nginx 配置读取失败时抛出
        """
        command = [self.nginx_binary]
        if self.nginx_config:
            command.extend(("-c", self.nginx_config))
        command.append("-T")
        try:
            output = subprocess.check_output(command, stderr=subprocess.STDOUT)
        except subprocess.CalledProcessError as exception:
            logging.error("Unable to read active Nginx configuration: %s", exception.output.decode("utf-8", "replace"))
            raise RuntimeError("active Nginx configuration could not be checked") from exception

        return output.decode("utf-8", "replace")


class RequestHandler(socketserver.StreamRequestHandler):
    """解析单行 JSON 请求并返回一个 JSON 结果。"""

    def handle(self) -> None:
        """处理单次连接，拒绝超长、未知操作和多余字段。

        @Name: handle
        @Description: 校验单行 JSON 协议并将异常转换成固定结构的失败响应。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Return None 已写入 JSON 响应
        @Throws None 请求异常会转换为失败响应并记录日志
        """
        self.connection.settimeout(15)
        raw_request = self.rfile.readline(MAX_REQUEST_BYTES + 1)
        self.connection.settimeout(None)
        try:
            if len(raw_request) > MAX_REQUEST_BYTES or not raw_request.endswith(b"\n"):
                raise ValueError("request exceeds the allowed size or is incomplete")
            request = json.loads(raw_request.decode("utf-8"))
            if not isinstance(request, dict):
                raise ValueError("request must be a JSON object")
            action = request.get("action")
            allowed_fields = {"action", "site_id"}
            if action == "sync":
                allowed_fields.update({"domain", "https_enabled", "certificate", "private_key"})
            if action not in ("sync", "remove") or set(request.keys()) - allowed_fields:
                raise ValueError("unsupported request")

            if action == "sync":
                self.server.deployer.synchronize(request)
            else:
                self.server.deployer.remove(request)
            response = {"success": True}
        except Exception as exception:
            logging.warning("Customer domain request rejected: %s", str(exception))
            response = {"success": False, "message": "deployment rejected"}

        try:
            self.wfile.write((json.dumps(response) + "\n").encode("utf-8"))
        except OSError:
            logging.info("Deployment client disconnected before reading the response")


if hasattr(socketserver, "UnixStreamServer"):
    class DomainRequestServer(socketserver.UnixStreamServer):
        """串行处理本地请求，避免并发写同一站点或域名配置。"""

        def __init__(self, socket_path: str, deployer: DomainDeployer) -> None:
            """创建 Unix Socket 服务并绑定唯一部署器实例。

            @Name: __init__
            @Description: 使用串行 Unix Socket 服务避免同一域名并行改写。
            @Author: cdkay
            @CreateTime: 2026-10-28 16:30:02
            @UpdateTime: 2026-10-28 17:53:21

            @Param str socket_path 受限 Unix Socket 路径
            @Param DomainDeployer deployer 配置部署执行器
            @Return None 服务已绑定 Socket
            @Throws OSError Socket 创建失败时抛出
            """
            self.deployer = deployer
            super(DomainRequestServer, self).__init__(socket_path, RequestHandler)
else:
    class DomainRequestServer(socketserver.TCPServer):
        """仅供非 Unix 平台导入模块与运行隔离单元测试。"""

        def __init__(self, socket_path: str, deployer: DomainDeployer) -> None:
            """拒绝在不支持 Unix Socket 的平台启动服务。

            @Name: __init__
            @Description: 仅允许在 Unix Socket 可用的平台启动部署服务。
            @Author: cdkay
            @CreateTime: 2026-10-28 16:30:02
            @UpdateTime: 2026-10-28 17:53:21

            @Param str socket_path 受限 Unix Socket 路径
            @Param DomainDeployer deployer 配置部署执行器
            @Return None 本构造器不会正常返回
            @Throws RuntimeError 当前平台不支持 Unix Socket
            """
            raise RuntimeError("customer domain deployment service requires Unix Socket support")


def create_deployer() -> DomainDeployer:
    """从 systemd 环境文件读取固定部署路径。

    @Name: create_deployer
    @Description: 仅从 root 管理的环境文件读取宿主机路径和内部上游地址。
    @Author: cdkay
    @CreateTime: 2026-10-28 16:30:02
    @UpdateTime: 2026-10-28 17:53:21

    @Return DomainDeployer 宿主机部署器
    @Throws OSError 路径无权访问或无法创建时抛出
    """
    return DomainDeployer(
        nginx_binary=os.environ.get("GEOFLOW_NGINX_BIN", DEFAULT_NGINX_BINARY),
        nginx_config=os.environ.get("GEOFLOW_NGINX_CONF", DEFAULT_NGINX_CONFIG),
        site_config_dir=os.environ.get("GEOFLOW_CUSTOM_DOMAIN_CONFIG_DIR", DEFAULT_SITE_CONFIG_DIR),
        certificate_dir=os.environ.get("GEOFLOW_CUSTOM_DOMAIN_CERT_DIR", DEFAULT_CERTIFICATE_DIR),
        backend=os.environ.get("GEOFLOW_CUSTOM_DOMAIN_BACKEND", DEFAULT_BACKEND),
    )


def main() -> int:
    """启动受 systemd 管控的 Unix Socket 服务。

    @Name: main
    @Description: 创建受限 Socket 并保持单线程请求处理，退出时只清理自身 Socket。
    @Author: cdkay
    @CreateTime: 2026-10-28 16:30:02
    @UpdateTime: 2026-10-28 17:53:21

    @Return int 正常停止时的退出码
    @Throws OSError 服务目录、Socket 或权限错误时抛出
    """
    if not hasattr(socketserver, "UnixStreamServer"):
        raise RuntimeError("customer domain deployment service requires Unix Socket support")

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    socket_path = os.environ.get("GEOFLOW_CUSTOM_DOMAIN_SOCKET", DEFAULT_SOCKET_PATH)
    socket_directory = os.path.dirname(socket_path)
    os.makedirs(socket_directory, mode=0o750, exist_ok=True)

    if os.path.lexists(socket_path):
        if not stat.S_ISSOCK(os.lstat(socket_path).st_mode):
            raise RuntimeError("refusing to replace a non-socket deployment path")
        probe = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        try:
            probe.settimeout(0.25)
            probe.connect(socket_path)
            raise RuntimeError("another customer domain deployment service is already listening")
        except socket.error:
            os.unlink(socket_path)
        finally:
            probe.close()

    os.umask(0o007)
    server = DomainRequestServer(socket_path, create_deployer())
    os.chmod(socket_path, 0o660)
    logging.info("Customer domain deployment service is listening on %s", socket_path)
    try:
        server.serve_forever()
    finally:
        server.server_close()
        if os.path.exists(socket_path) and stat.S_ISSOCK(os.lstat(socket_path).st_mode):
            os.unlink(socket_path)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
