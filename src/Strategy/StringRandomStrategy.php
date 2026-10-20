<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class StringRandomStrategy extends AbstractStrategy
{
    private string $prefix = '';
    private int $length = 8;

    /**
     * Constructs a new instance.
     *
     * @param      string  $strategyName
     * @param      array<string, string|int>   $options
     */
    public function __construct(string $strategyName = "string_random", array $options = [])
    {
        parent::__construct($strategyName, $options);
        $this->prefix = isset($options['prefix'])? strval($options['prefix']) : '';
        $this->length = isset($options['length'])? intval($options['length']) : 8;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        if ($salt === null) {
            return '';
        }
        $id = (int) hexdec(substr($salt, 0, 13));

        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $randomPart = '';
        $currentIndex = $id;

        for ($i = 0; $i < $this->length; $i++) {
            $randomPart .= $chars[$currentIndex % strlen($chars)];
            $currentIndex = (int) ($currentIndex / strlen($chars));
        }

        return $this->prefix . $randomPart;
    }
}
