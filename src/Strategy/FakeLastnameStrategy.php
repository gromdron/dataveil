<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class FakeLastnameStrategy extends AbstractStrategy
{
    /**
     * @var array<string>
     */
    private static array $lastNames = [
        'Иванов', 'Петров', 'Смирнов', 'Кузнецов', 'Попов', 'Соколов', 'Лебедев', 'Козлов', 'Новиков', 'Морозов',
        'Волков', 'Алексеев', 'Щербаков', 'Борисов', 'Дмитриев', 'Семенов', 'Павлов', 'Васильев', 'Марков',
        'Воронин', 'Федоров', 'Михайлов', 'Беляев', 'Тарасов', 'Баранов', 'Ершов', 'Андреев', 'Макаров', 'Никитин',
        'Захаров', 'Березин', 'Сорокин', 'Краснов', 'Головин', 'Калинин', 'Кравцов', 'Филиппов', 'Кириллов', 'Матвеев',
    ];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $strategyName = "lastname_fake", array $options = [])
    {
        parent::__construct($strategyName, $options);
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        if ($salt === null) {
            return '';
        }
        $id = (int) hexdec(substr($salt, 0, 13));

        return self::$lastNames[$id % count(self::$lastNames)];
    }
}
