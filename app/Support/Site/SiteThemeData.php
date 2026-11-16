<?php

/**
 * Created by Codex.
 * @Date: 2026-11-16
 * @Time: 10:50:27
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： SiteThemeData.php
 * @Description: 提供官网主题公共数据的只读数组与对象双重访问形式。
 */

namespace App\Support\Site;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

final class SiteThemeData implements ArrayAccess, IteratorAggregate, JsonSerializable
{
    /** @var array<string|int, mixed> */
    private array $values;

    /**
     * @Name: __construct
     * @Description: 递归封装关联数组，使主题可用数组或对象语法读取同一份数据，列表仍保持可遍历数组。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 10:50:27
     * @UpdateTime: 2026-11-16 10:50:27
     * @Param array<string|int, mixed> $values 公共主题数据
     * @Return void 无返回值
     * @Throws 无
     */
    public function __construct(array $values)
    {
        $this->values = [];
        foreach ($values as $key => $value) {
            $this->values[$key] = $this->normalize($value);
        }
    }

    /**
     * @Name: __get
     * @Description: 以对象属性形式读取主题数据；未知属性返回 null 以兼容可选字段。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 10:50:27
     * @UpdateTime: 2026-11-16 10:50:27
     * @Param string $name 主题数据字段名
     * @Return mixed 字段值或 null
     * @Throws 无
     */
    public function __get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    /**
     * @Name: __isset
     * @Description: 判断可选主题属性是否存在，供 empty() 与 isset() 使用。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 10:50:27
     * @UpdateTime: 2026-11-16 10:50:27
     * @Param string $name 主题数据字段名
     * @Return bool 字段是否存在且非 null
     * @Throws 无
     */
    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    /**
     * @Name: offsetExists
     * @Description: 判断关联数组语法访问的主题字段是否存在。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 10:50:27
     * @UpdateTime: 2026-11-16 10:50:27
     * @Param mixed $offset 主题数据字段键
     * @Return bool 字段是否存在
     * @Throws 无
     */
    public function offsetExists(mixed $offset): bool
    {
        return (is_string($offset) || is_int($offset)) && array_key_exists($offset, $this->values);
    }

    /**
     * @Name: offsetGet
     * @Description: 以关联数组语法读取主题字段；未知字段返回 null 以兼容可选字段。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 10:50:27
     * @UpdateTime: 2026-11-16 10:50:27
     * @Param mixed $offset 主题数据字段键
     * @Return mixed 字段值或 null
     * @Throws 无
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->offsetExists($offset) ? $this->values[$offset] : null;
    }

    /**
     * @Name: offsetSet
     * @Description: 拒绝修改只读主题数据，避免视图渲染过程污染共享数据。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 10:50:27
     * @UpdateTime: 2026-11-16 10:50:27
     * @Param mixed $offset 主题数据字段键
     * @Param mixed $value 主题数据字段值
     * @Return void 不返回值
     * @Throws LogicException 视图尝试写入公共主题数据
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('官网主题公共数据为只读。');
    }

    /**
     * @Name: offsetUnset
     * @Description: 拒绝删除只读主题数据字段，避免视图渲染过程污染共享数据。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 10:50:27
     * @UpdateTime: 2026-11-16 10:50:27
     * @Param mixed $offset 主题数据字段键
     * @Return void 不返回值
     * @Throws LogicException 视图尝试删除公共主题数据字段
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('官网主题公共数据为只读。');
    }

    /**
     * @Name: getIterator
     * @Description: 按字段插入顺序遍历主题数据，供 Blade 的 foreach 使用。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 10:50:27
     * @UpdateTime: 2026-11-16 10:50:27
     * @Return Traversable<string|int, mixed> 主题数据迭代器
     * @Throws 无
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }

    /**
     * @Name: jsonSerialize
     * @Description: 返回可安全序列化的主题数据，供 JSON-LD 等视图输出使用。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 10:50:27
     * @UpdateTime: 2026-11-16 10:50:27
     * @Return array<string|int, mixed> 主题数据数组
     * @Throws 无
     */
    public function jsonSerialize(): array
    {
        return $this->values;
    }

    /**
     * @Name: normalize
     * @Description: 将关联数组递归封装为主题数据对象，同时保留列表语义与标量值。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 10:50:27
     * @UpdateTime: 2026-11-16 10:50:27
     * @Param mixed $value 待规范化的字段值
     * @Return mixed 只读数据对象、列表或原标量
     * @Throws 无
     */
    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $nestedValue) {
            $normalized[$key] = $this->normalize($nestedValue);
        }

        return array_is_list($normalized) ? $normalized : new self($normalized);
    }
}
