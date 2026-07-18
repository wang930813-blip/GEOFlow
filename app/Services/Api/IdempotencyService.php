<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 16:30
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： IdempotencyService.php
 *
 * @Description: 提供按站点、账号和 API Token 隔离的写接口幂等占位、响应缓存与并发保护。
 */

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\ApiIdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

class IdempotencyService
{
    private const RESERVATION_ATTRIBUTE = 'api_idempotency_reservation';

    private const STATE_PROCESSING = 'processing';

    private const STATE_COMPLETED = 'completed';

    /**
     * @Name: normalizePayload
     *
     * @Description: 递归规范化请求载荷，确保关联数组按键排序后生成稳定哈希。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: mixed $value 待规范化请求载荷
     *
     * @Return: mixed 规范化后的请求载荷
     */
    public static function normalizePayload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'normalizePayload'], $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalizePayload($item);
        }

        return $value;
    }

    /**
     * @Name: requestHash
     *
     * @Description: 生成规范化请求体哈希，用于识别同一幂等键是否对应相同业务请求。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: array<string, mixed> $body 请求体
     *
     * @Return: string SHA-256 请求体哈希
     */
    public static function requestHash(array $body): string
    {
        return hash('sha256', self::encodeJson(self::normalizePayload($body)));
    }

    /**
     * @Name: maybeReplayJson
     *
     * @Description: 原子占用幂等键；已完成请求返回缓存，并发处理中请求在业务执行前直接拒绝。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Param: string $routeKey 稳定路由标识
     *
     * @Return: JsonResponse|null 已完成请求的缓存响应，首次请求返回 null
     */
    public static function maybeReplayJson(Request $request, string $routeKey): ?JsonResponse
    {
        $clientKey = self::clientKey($request);
        if ($clientKey === null) {
            return null;
        }

        $context = self::authContext($request);
        $requestHash = self::requestHash($request->all());
        $storageKey = self::storageKey($clientKey, $context);
        $now = now();
        $inserted = ApiIdempotencyKey::withoutGlobalScope('current_site')->insertOrIgnore([
            'site_id' => $context->siteId,
            'idempotency_key' => $storageKey,
            'route_key' => $routeKey,
            'request_hash' => $requestHash,
            'response_body' => '{}',
            'response_status' => 0,
            'state' => self::STATE_PROCESSING,
            'processing_started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted > 0) {
            $request->attributes->set(self::RESERVATION_ATTRIBUTE, [
                'storage_key' => $storageKey,
                'route_key' => $routeKey,
                'request_hash' => $requestHash,
                'site_id' => $context->siteId,
            ]);

            return null;
        }

        $row = self::reservationQuery($storageKey, $routeKey, $context->siteId)->first();
        if (! $row instanceof ApiIdempotencyKey) {
            throw new ApiException('idempotency_unavailable', '幂等状态暂时不可用，请稍后重试', 503);
        }

        if (! hash_equals((string) $row->request_hash, $requestHash)) {
            throw new ApiException('idempotency_conflict', '同一个幂等键对应了不同的请求内容', 409);
        }

        if ((string) $row->state !== self::STATE_COMPLETED) {
            throw new ApiException('idempotency_in_progress', '相同操作正在处理中，请勿重复提交', 409);
        }

        $decoded = json_decode((string) $row->response_body, true);
        if (! is_array($decoded)) {
            throw new ApiException('idempotency_corrupted', '幂等缓存数据损坏', 500);
        }

        return response()->json($decoded, (int) $row->response_status);
    }

    /**
     * @Name: remember
     *
     * @Description: 将当前请求持有的处理中占位原子更新为完整响应，禁止无占位覆盖已有缓存。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Param: string $routeKey 稳定路由标识
     *
     * @Param: array<string, mixed> $envelope 完整响应信封
     *
     * @Param: int $status HTTP 状态码
     *
     * @Return: void
     */
    public static function remember(Request $request, string $routeKey, array $envelope, int $status): void
    {
        $reservation = $request->attributes->get(self::RESERVATION_ATTRIBUTE);
        if (! is_array($reservation) || ($reservation['route_key'] ?? null) !== $routeKey) {
            return;
        }

        $updated = self::reservationQuery(
            (string) $reservation['storage_key'],
            $routeKey,
            isset($reservation['site_id']) ? (int) $reservation['site_id'] : null,
        )
            ->where('request_hash', (string) $reservation['request_hash'])
            ->where('state', self::STATE_PROCESSING)
            ->update([
                'response_body' => self::encodeJson($envelope),
                'response_status' => $status,
                'state' => self::STATE_COMPLETED,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ApiException('idempotency_finalize_failed', '幂等响应保存失败', 500);
        }

        $request->attributes->remove(self::RESERVATION_ATTRIBUTE);
    }

    /**
     * @Name: rememberFromResponse
     *
     * @Description: 从 JSON 响应解析完整信封并完成当前幂等占位。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Param: string $routeKey 稳定路由标识
     *
     * @Param: JsonResponse $response 待缓存 JSON 响应
     *
     * @Return: void
     */
    public static function rememberFromResponse(Request $request, string $routeKey, JsonResponse $response): void
    {
        $payload = json_decode((string) $response->getContent(), true);
        if (! is_array($payload)) {
            return;
        }

        self::remember($request, $routeKey, $payload, $response->getStatusCode());
    }

    /**
     * @Name: rememberApiException
     *
     * @Description: 将已归一化业务异常保存为幂等结果，保证相同失败请求重试时保持同一响应。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Param: JsonResponse $response 已归一化业务异常响应
     *
     * @Return: void
     */
    public static function rememberApiException(Request $request, JsonResponse $response): void
    {
        $reservation = $request->attributes->get(self::RESERVATION_ATTRIBUTE);
        if (! is_array($reservation) || ! is_string($reservation['route_key'] ?? null)) {
            return;
        }

        self::rememberFromResponse($request, $reservation['route_key'], $response);
    }

    /**
     * @Name: releaseValidationReservation
     *
     * @Description: 请求尚未进入业务逻辑且验证失败时释放占位，允许客户端修正载荷后继续使用同一幂等键。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Return: void
     */
    public static function releaseValidationReservation(Request $request): void
    {
        $reservation = $request->attributes->get(self::RESERVATION_ATTRIBUTE);
        if (! is_array($reservation)) {
            return;
        }

        self::reservationQuery(
            (string) $reservation['storage_key'],
            (string) $reservation['route_key'],
            isset($reservation['site_id']) ? (int) $reservation['site_id'] : null,
        )
            ->where('request_hash', (string) $reservation['request_hash'])
            ->where('state', self::STATE_PROCESSING)
            ->delete();
        $request->attributes->remove(self::RESERVATION_ATTRIBUTE);
    }

    /**
     * @Name: clientKey
     *
     * @Description: 校验写请求幂等键长度和字符集，避免数据库截断及不可见字符污染。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Return: string|null 合法客户端幂等键，未提供时返回 null
     */
    private static function clientKey(Request $request): ?string
    {
        $rawKey = $request->header('X-Idempotency-Key');
        if (! is_string($rawKey) || trim($rawKey) === '' || ! in_array($request->method(), ['POST', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        $key = trim($rawKey);
        if (strlen($key) < 8 || strlen($key) > 120 || preg_match('/^[A-Za-z0-9._:-]+$/D', $key) !== 1) {
            throw new ApiException('validation_failed', 'X-Idempotency-Key 必须为 8 至 120 位字母、数字或 . _ : -', 422);
        }

        return $key;
    }

    /**
     * @Name: authContext
     *
     * @Description: 获取经 Bearer 中间件注入的认证上下文，拒绝脱离账号和 Token 的幂等请求。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Return: ApiAuthContext 认证上下文
     */
    private static function authContext(Request $request): ApiAuthContext
    {
        $context = $request->attributes->get('api_auth');
        $tokenId = $context instanceof ApiAuthContext ? (int) ($context->token['id'] ?? 0) : 0;
        if (! $context instanceof ApiAuthContext || $context->auditAdminId <= 0 || $tokenId <= 0) {
            throw new ApiException('unauthorized', '幂等写操作缺少有效认证上下文', 401);
        }

        return $context;
    }

    /**
     * @Name: storageKey
     *
     * @Description: 将客户端幂等键绑定到站点、账号和具体 Token，防止跨用户缓存命中或冲突。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: string $clientKey 客户端幂等键
     *
     * @Param: ApiAuthContext $context 认证上下文
     *
     * @Return: string 隔离后的 SHA-256 存储键
     */
    private static function storageKey(string $clientKey, ApiAuthContext $context): string
    {
        return hash('sha256', implode(':', [
            (string) ($context->siteId ?? 0),
            (string) $context->auditAdminId,
            (string) ((int) ($context->token['id'] ?? 0)),
            $clientKey,
        ]));
    }

    /**
     * @Name: reservationQuery
     *
     * @Description: 绕过隐式站点作用域后使用显式站点和隔离键定位唯一幂等记录。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: string $storageKey 隔离后的存储键
     *
     * @Param: string $routeKey 稳定路由标识
     *
     * @Param: int|null $siteId 绑定站点编号
     *
     * @Return: \Illuminate\Database\Eloquent\Builder<ApiIdempotencyKey> 幂等记录查询构造器
     */
    private static function reservationQuery(string $storageKey, string $routeKey, ?int $siteId)
    {
        return ApiIdempotencyKey::withoutGlobalScope('current_site')
            ->where('idempotency_key', $storageKey)
            ->where('route_key', $routeKey)
            ->when(
                $siteId !== null,
                fn ($query) => $query->where('site_id', $siteId),
                fn ($query) => $query->whereNull('site_id'),
            );
    }

    /**
     * @Name: encodeJson
     *
     * @Description: 使用稳定选项编码 JSON，编码失败时返回标准 API 异常且不暴露原始载荷。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Param: mixed $value 待编码值
     *
     * @Return: string JSON 字符串
     */
    private static function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ApiException('idempotency_encode_failed', '幂等缓存数据编码失败', 500);
        }
    }
}
