# Руководство по конфигурации

## Обзор

Файл `configuration.yaml` определяет, как DataVeil должен анонимизировать вашу базу данных. Конфигурация содержит три основных раздела:

1. **database**: настройки подключения к базе данных.
2. **rules**: простые правила анонимизации.
3. **consistency_groups**: сложные взаимосвязи, требующие согласованности данных.

## Раздел database

Содержит информацию для подключения к базе данных.
В текущей версии в качестве источника поддерживается только `bitrix_settings`.
Он описывается параметрами:
- `settings_file` - путь к файлу конфигурации битрикса 
- `connection_name` - имя соединения с базой данных

```yaml
database:
    source: "bitrix_settings"
    bitrix:
        settings_file: "/home/bitrix/www/bitrix/.settings.php"
        connection_name: "default"
```

## Раздел rules

В разделе описываются простые правила: очистка таблицы или замена простых полей.
В настоящее время в разделе `rules` допустим только один ключ `tables` описывающий правила для таблиц.
Каждое правило состоит из:
- `name` - названия таблицы
- `action` - строка описывающее действие (`truncate`, `update`)
- `comment` - опциональный комментарий для таблицы
- `fields` - описание обезличиваемых столбцов
- `where` - ограничения (SQL WHERE) для `update` действия

### Очистка таблицы

Пример полной очистки таблицы:

```yaml
rules:
    tables:
        - name: "b_search_content"
          action: "truncate"
          comment: "Поисковой индекс"
```

### Замена простых полей таблицы

Заменить конкретные значения столбцов на фейковые данные:

```yaml
rules:
    tables:
        - name: "b_user"
          action: "update"
          fields:
              - column: "EMAIL"
                strategy: "email_fake"
                salt_source: "primary"
              - column: "LOGIN"
                strategy: "string_random"
                options: { length: 10, prefix: "user_" }
                salt_source: "primary"
              - column: "NAME"
                strategy: "name_fake"
                salt_source: "primary"
              - column: "PERSONAL_PHONE"
                strategy: "phone_fake"
                salt_source: "primary"
          where: "ID NOT IN (1)"
```

В приведенном примере будет произведено простое обезличивание таблицы `b_user` (все записи у которых ID != 1).
К столбцам будут приведены правила:
- `EMAIL` - будет заменен на случайный
- `LOGIN` - будет состоять из 10 символов с префиксом `user_`
- `NAME` - будет заменен на имя
- `PERSONAL_PHONE` - будет заменен на телефон

> **Важно:** Параметр `salt_source: "primary"` указывает, что для генерации фейкового значения
> используется первичный ключ записи. Это гарантирует уникальность результата для каждой строки
> и предотвращает коллизии при наличии уникальных индексов (например, `ix_login` в `b_user`).
> Без `salt_source` детерминированные стратегии используют `crc32(original_value)` в качестве сида,
> что может привести к одинаковому результату для разных исходных значений.

## Группы согласованности

Для таблиц со сложными взаимосвязями, где данные должны оставаться синхронизированными.

### Структура

```yaml
consistency_groups:
    - id: "group_name"
      comment: "Описание"
      anchor:
          table: "source_table"
          id_column: "ID"
          context_columns: ["COLUMN1", "COLUMN2"]
          filters:
              - column: "TYPE_ID"
                value: "PHONE"
      generator:
          deterministic: true
          strategy: "phone_fake"
          salt_source: "primary"
      targets:
          - table: "target_table1"
            column: "field_name"
            join: { key_column: "ID", ref_column: "ID" }
          - table: "target_table2"
            column: "serialized_field"
            join:
                type: "serialized_value_match"
                entity_id_column: "OWNER_ID"
            serialization:
                type: "php_serialize"
                match_key: "PHONE"
                match_value_source: "anchor.VALUE"
```

### Типовые сценарии

#### Сценарий 1: Простой якорь → цель

```yaml
consistency_groups:
    - id: "phone_numbers"
      anchor:
          table: "b_crm_field_multi"
          id_column: "ID"
          context_columns: ["ENTITY_ID", "VALUE", "TYPE_ID"]
          filters:
              - column: "TYPE_ID"
                value: "PHONE"
      generator:
          deterministic: true
          strategy: "phone_fake"
          salt_source: "primary"
      targets:
          - table: "b_crm_field_multi"
            column: "VALUE"
            join:
                key_column: "ID"
                ref_column: "ID"
```

#### Сценарий 2: Якорь → несколько целей

```yaml
consistency_groups:
    - id: "email_sync"
      anchor:
          table: "b_crm_field_multi"
          id_column: "ID"
          context_columns: ["ENTITY_ID", "VALUE", "TYPE_ID"]
      generator:
          deterministic: true
          strategy: "email_fake"
          salt_source: "primary"
      targets:
          - table: "b_crm_field_multi"
            column: "VALUE"
            join: { key_column: "ID", ref_column: "ID" }
          - table: "b_crm_act_comm"
            column: "ENTITY_SETTINGS"
            join:
                type: "serialized_value_match"
                entity_id_column: "OWNER_ID"
                ref_entity_column: "ENTITY_ID"
            serialization:
                type: "php_serialize"
                match_key: "EMAIL"
                match_value_source: "anchor.VALUE"
```

## Параметры стратегии

### Управление детерминизмом

Каждое поле в `rules.tables` поддерживает параметр `salt_source`, который определяет источник «соли»
для генерации фейкового значения:

- `salt_source: "primary"` — детерминированный на основе первичного ключа записи (рекомендуется для `rules`)
- `salt_source: "value"` — детерминированный на основе оригинального значения поля

> **Рекомендация:** Всегда указывайте `salt_source: "primary"` для полей со стратегиями, отличными от `empty`.
> Это гарантирует:
> - Уникальность результата для каждой строки (два разных ID всегда дадут разные значения)
> - Предсказуемость при повторных запусках (один и тот же ID всегда даст одно значение)
> - Отсутствие коллизий при наличии уникальных индексов на столбце
>
> Без `salt_source` стратегии используют `crc32(оригинальное_значение)` как сид, и разные
> исходные значения могут дать одинаковый результат (коллизия CRC32).

### Параметры string_random

```yaml
fields:
    - column: "LOGIN"
      strategy: "string_random"
      options:
          prefix: "user_"
          length: 10
      salt_source: "primary"
```

## Полный пример конфигурации

```yaml
version: 1.0

database:
    source: "bitrix_settings"
    bitrix:
        settings_file: "/home/bitrix//www/bitrix/.settings.php"
        connection_name: "default"

rules:
    tables:
        - name: "b_search_content"
          action: "truncate"
          comment: "Индекс поиска - безопасно удалить"

        - name: "b_user"
          action: "update"
          fields:
              - column: "EMAIL"
                strategy: "email_fake"
                salt_source: "primary"
              - column: "LOGIN"
                strategy: "string_random"
                options: { length: 10, prefix: "user_" }
                salt_source: "primary"
              - column: "NAME"
                strategy: "name_fake"
                salt_source: "primary"
              - column: "LAST_NAME"
                strategy: "lastname_fake"
                salt_source: "primary"
              - column: "PERSONAL_PHONE"
                strategy: "phone_fake"
                salt_source: "primary"
          where: "ID NOT IN (1)"

consistency_groups:
    - id: "crm_phone_unique"
      anchor:
          table: "b_crm_field_multi"
          id_column: "ID"
          context_columns: ["ENTITY_ID", "VALUE", "TYPE_ID"]
          filters:
              - column: "TYPE_ID"
                value: "PHONE"
      generator:
          deterministic: true
          strategy: "phone_fake"
          salt_source: "primary"
      targets:
          - table: "b_crm_field_multi"
            column: "VALUE"
            join: { key_column: "ID", ref_column: "ID" }
          - table: "b_crm_act_comm"
            column: "ENTITY_SETTINGS"
            join:
                type: "serialized_value_match"
                entity_id_column: "OWNER_ID"
            serialization:
                type: "php_serialize"
                match_key: "PHONE"
                match_value_source: "anchor.VALUE"

    - id: "crm_email_unique"
      anchor:
          table: "b_crm_field_multi"
          id_column: "ID"
          context_columns: ["ENTITY_ID", "VALUE", "TYPE_ID"]
          filters:
              - column: "TYPE_ID"
                value: "EMAIL"
      generator:
          deterministic: true
          strategy: "email_fake"
          salt_source: "primary"
      targets:
          - table: "b_crm_field_multi"
            column: "VALUE"
            join: { key_column: "ID", ref_column: "ID" }
          - table: "b_crm_act_comm"
            column: "ENTITY_SETTINGS"
            join:
                type: "serialized_value_match"
                entity_id_column: "OWNER_ID"
            serialization:
                type: "php_serialize"
                match_key: "EMAIL"
                match_value_source: "anchor.VALUE"
```
