# CreBee Bridge Agent

运行位置：安装并登录 CreBee 客户端的运维电脑。

云端服务器不能直接访问运维电脑的 `127.0.0.1:3456`。这个 Agent 负责主动连接 GEOFlow 云端，拉取发布任务，再调用本机 CreBee API 完成发布，并把进度回传云端。

## 1. 云端创建 Agent 凭据

在 GEOFlow 服务器执行：

```bash
php artisan crebee:agent-create agent-office-1 --name="Office Bridge"
```

命令会输出：

```text
CREBEE_AGENT_ID=agent-office-1
CREBEE_AGENT_SECRET=...
```

secret 只显示一次，写入本地 Agent 的 `.env`。

## 2. 本地配置

```bash
cd tools/crebee-bridge-agent
cp .env.example .env
```

修改：

```text
GEOFLOW_BASE_URL=https://你的正式域名
CREBEE_AGENT_ID=...
CREBEE_AGENT_SECRET=...
CREBEE_BASE_URL=http://127.0.0.1:3456
```

## 3. 启动

```bash
node src/index.mjs
```

Windows 本机可以直接双击：

```text
start-agent.cmd
```

如果要隐藏窗口后台运行，在 PowerShell 执行：

```powershell
powershell -ExecutionPolicy Bypass -File .\start-agent-hidden.ps1
```

Node 要求 18 或更高版本。当前版本不依赖 npm 包。

## 4. 打包成 exe

当前 Agent 是纯 Node 脚本，打包成 exe 推荐用 `pkg` 或 `nexe`。需要联网安装打包工具：

```bash
npm install -g pkg
pkg src/index.mjs --targets node20-win-x64 --output dist/crebee-bridge-agent.exe
```

注意：即使打成 exe，仍然需要旁边放 `.env` 配置文件，因为 Agent ID、Secret、云端地址不应该硬编码进程序。

## 当前能力

- 定时心跳上报。
- 定时调用本机 `POST /galic/v1/account/getAll` 并同步云端账号。
- 轮询云端 `GET /api/v1/crebee-agent/jobs/next`。
- 调用本机 `POST /galic/v1/platform/publish/batch`。
- 监听本机 CreBee SSE 进度并回传云端；超时后按提交结果兜底。

后续可以在此基础上补素材下载到本地路径、Windows 服务化启动和更细的平台参数配置。
