"""
Created by Codex.
@Date: 2026-10-28
@Time: 17:59:50
@Author: cdkay
@Email: network@iyuanma.net

@File：test_server.py
@Description: 验证客户域名部署服务的隔离、冲突拒绝和失败回滚行为。
"""

import os
import tempfile
import unittest

from server import DomainDeployer


class DomainDeployerTest(unittest.TestCase):
    """覆盖部署器面向客户站点配置的安全行为。"""

    def setUp(self):
        """创建临时配置目录并注入不触碰系统 Nginx 的命令执行器。

        @Name: setUp
        @Description: 为每个测试准备隔离目录、伪 Nginx 命令和空配置快照。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Return None 测试部署器可用
        @Throws OSError 临时目录创建失败时抛出
        """
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.site_config_dir = os.path.join(self.temporary_directory.name, "sites")
        self.certificate_dir = os.path.join(self.temporary_directory.name, "certificates")
        self.commands = []
        self.fail_test_count = 0
        self.fail_reload_count = 0
        self.nginx_output = "# configuration file /etc/nginx/nginx.conf:\n"

        def runner(arguments):
            """记录固定参数并按测试场景模拟 Nginx 命令错误。

            @Name: runner
            @Description: 隔离 Nginx 命令副作用并控制校验或 reload 失败次数。
            @Author: cdkay
            @CreateTime: 2026-10-28 16:30:02
            @UpdateTime: 2026-10-28 17:53:21

            @Param tuple arguments Nginx 命令参数
            @Return None 命令成功时返回
            @Throws RuntimeError 注入失败次数大于零时抛出
            """
            self.commands.append(arguments)
            if arguments == ("-t",) and self.fail_test_count > 0:
                self.fail_test_count -= 1
                raise RuntimeError("simulated nginx test failure")
            if arguments == ("-s", "reload") and self.fail_reload_count > 0:
                self.fail_reload_count -= 1
                raise RuntimeError("simulated nginx reload failure")

        self.deployer = DomainDeployer(
            nginx_binary="/unused/nginx",
            nginx_config="",
            site_config_dir=self.site_config_dir,
            certificate_dir=self.certificate_dir,
            nginx_runner=runner,
            nginx_dump=lambda: self.nginx_output,
        )

    def tearDown(self):
        """移除本测试拥有的临时目录。

        @Name: tearDown
        @Description: 仅清理本测试创建的 TemporaryDirectory。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Return None 临时文件已清理
        @Throws OSError 临时目录清理失败时抛出
        """
        self.temporary_directory.cleanup()

    def test_synchronize_writes_only_the_site_scoped_http_configuration(self):
        """验证 HTTP 配置固定代理至本机并只修改站点专属文件。

        @Name: test_synchronize_writes_only_the_site_scoped_http_configuration
        @Description: 覆盖域名归一化、代理头和 reload 命令顺序。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Return None 所有文件和 Nginx 命令符合预期
        @Throws AssertionError 实际输出不符合预期时抛出
        """
        self.deployer.synchronize({
            "site_id": 17,
            "domain": "News.Example.test.",
            "https_enabled": False,
            "certificate": "",
            "private_key": "",
        })

        config_path = os.path.join(self.site_config_dir, "17.conf")
        with open(config_path, "r") as config_file:
            config = config_file.read()
        self.assertIn("server_name news.example.test;", config)
        self.assertIn("client_max_body_size 64m;", config)
        self.assertIn("proxy_pass http://127.0.0.1:18080;", config)
        self.assertIn("proxy_set_header X-Forwarded-Proto $scheme;", config)
        self.assertIn("proxy_read_timeout 300;", config)
        self.assertEqual([("-t",), ("-s", "reload")], self.commands)

    def test_synchronize_rejects_injection_and_existing_wildcard_hosts(self):
        """验证路径/指令注入和其他虚拟主机的通配域名占用都会被拒绝。

        @Name: test_synchronize_rejects_injection_and_existing_wildcard_hosts
        @Description: 覆盖不安全域名及展开配置中多行通配域名冲突。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:59:50

        @Return None 非法请求未产生站点配置
        @Throws AssertionError 实际行为与拒绝规则不符时抛出
        """
        with self.assertRaises(ValueError):
            self.deployer.synchronize({
                "site_id": 18,
                "domain": "client.example.test; include /tmp/evil.conf",
                "https_enabled": False,
                "certificate": "",
                "private_key": "",
            })

        with self.assertRaises(ValueError):
            self.deployer.synchronize({
                "site_id": 18,
                "domain": "192.0.2.10",
                "https_enabled": False,
                "certificate": "",
                "private_key": "",
            })

        self.nginx_output += "server {\n    server_name\n        *.example.test;\n}\n"
        with self.assertRaises(RuntimeError):
            self.deployer.synchronize({
                "site_id": 18,
                "domain": "client.example.test",
                "https_enabled": False,
                "certificate": "",
                "private_key": "",
            })

        self.assertFalse(os.path.exists(os.path.join(self.site_config_dir, "18.conf")))

    def test_nginx_test_failure_restores_previous_configuration_and_key(self):
        """验证校验失败时恢复旧代理配置和 0600 私钥且不会留下半配置。

        @Name: test_nginx_test_failure_restores_previous_configuration_and_key
        @Description: 验证配置测试失败时原子恢复配置和密钥权限。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Return None 旧文件内容及权限保持不变
        @Throws AssertionError 回滚结果不符合预期时抛出
        """
        first_request = {
            "site_id": 19,
            "domain": "first.example.test",
            "https_enabled": True,
            "certificate": "-----BEGIN CERTIFICATE-----\nold\n-----END CERTIFICATE-----",
            "private_key": "-----BEGIN PRIVATE KEY-----\nold\n-----END PRIVATE KEY-----",
        }
        self.deployer.synchronize(first_request)
        config_path = os.path.join(self.site_config_dir, "19.conf")
        private_key_path = os.path.join(self.certificate_dir, "19.key")
        with open(config_path, "rb") as config_file:
            previous_config = config_file.read()
        with open(private_key_path, "rb") as private_key_file:
            previous_key = private_key_file.read()
        previous_mode = os.stat(private_key_path).st_mode & 0o777

        self.commands = []
        self.fail_test_count = 1
        replacement = dict(first_request, domain="second.example.test", private_key="replacement")
        with self.assertRaises(RuntimeError):
            self.deployer.synchronize(replacement)

        with open(config_path, "rb") as config_file:
            self.assertEqual(previous_config, config_file.read())
        with open(private_key_path, "rb") as private_key_file:
            self.assertEqual(previous_key, private_key_file.read())
        self.assertEqual(previous_mode, os.stat(private_key_path).st_mode & 0o777)
        self.assertEqual(("-t",), self.commands[0])

    def test_remove_restores_configuration_when_reload_fails(self):
        """验证清理 reload 失败时磁盘上的站点配置被恢复。

        @Name: test_remove_restores_configuration_when_reload_fails
        @Description: 验证删除配置后的 reload 失败会恢复原始文件。
        @Author: cdkay
        @CreateTime: 2026-10-28 16:30:02
        @UpdateTime: 2026-10-28 17:53:21

        @Return None 站点配置继续存在且旧 HTTP 代理未被误删
        @Throws AssertionError 失败回滚未保留配置时抛出
        """
        self.deployer.synchronize({
            "site_id": 20,
            "domain": "remove.example.test",
            "https_enabled": False,
            "certificate": "",
            "private_key": "",
        })
        config_path = os.path.join(self.site_config_dir, "20.conf")
        self.fail_reload_count = 1

        with self.assertRaises(RuntimeError):
            self.deployer.remove({"site_id": 20, "action": "remove"})

        self.assertTrue(os.path.isfile(config_path))
        with open(config_path, "r") as config_file:
            self.assertIn("remove.example.test", config_file.read())


if __name__ == "__main__":
    unittest.main()
