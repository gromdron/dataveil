# Руководство по конфигурации

## Обзор

Файл `configuration.yaml` определяет, как DataVeil должен анонимизировать вашу базу данных. Конфигурация содержит четыре раздела:

1. **database**: настройки подключения к базе данных.
2. **settings**: необязательные общие настройки (секретный ключ для детерминированной генерации).
3. **rules**: простые правила анонимизации.
4. **consistency_groups**: сложные взаимосвязи, требующие согласованности данных.

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

## Раздел settings

Необязательный раздел общих настройек прогона. Единственный параметр — `secret_key`:
секретный ключ, которым ключуется HMAC-SHA256 при выводе сида для детерминированных стратегий.

```yaml
settings:
    secret_key: "change-me-in-production"
```

Если раздел отсутствует, DataVeil молча использует встроенный ключ (предупреждений не выводится).
Встроенный ключ — часть исходного кода DataVeil: любой, кто с ним знаком, может подтверждать
кандидатов словарной атакой по обезличенному выводу. Поэтому в рабочих конфигурациях рекомендуется
задавать собственный `secret_key`.

- Один и тот же ключ даёт воспроизводимые значения между запусками.
- Разные ключи дают разные значения: один и тот же конфиг с другим ключом обезличит данные иначе.

Если раздел `settings` присутствует, ключ `secret_key` обязателен: он должен быть непустой строкой,
иначе валидация конфигурации завершится ошибкой.

## Раздел rules

В разделе описываются простые правила: очистка таблицы или замена простых полей.
В настоящее время в разделе `rules` допустим только один ключ `tables` описывающий правила для таблиц.
Каждое правило состоит из:
- `name` - названия таблицы
- `action` - строка описывающее действие (`truncate`, `delete`, `update`)
- `comment` - опциональный комментарий для таблицы
- `fields` - описание обезличиваемых столбцов
- `where` - ограничения (SQL WHERE) для `update` действия

### Очистка таблицы

DataVeil поддерживает два способа очистки таблицы.

**`truncate`** — выполняет `TRUNCATE TABLE`. Быстро даже на огромных таблицах, но `TRUNCATE` в MySQL/MariaDB вызывает неявный COMMIT ([документация MySQL](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html)): он фиксирует все изменения, сделанные до него, и последующая работа идёт уже вне транзакции. Если конфигурация содержит хотя бы одно `truncate`-правило, прогон **не атомарен**: при ошибке на середине уже выполненные изменения не откатятся. DataVeil выводит предупреждение в начале такого прогона.

**`delete`** — выполняет `DELETE FROM`. Работает внутри транзакции и сохраняет атомарность прогона: при ошибке всё откатывается целиком. Плата — заметно медленнее на таблицах с миллионами строк и несброшенный счётчик `AUTO_INCREMENT`.

Пример:

```yaml
rules:
    tables:
        - name: "b_search_content"
          action: "truncate"
          comment: "Поисковой индекс"

        - name: "b_mail_message"
          action: "delete"
          comment: "Очистка с сохранением атомарности прогона"
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
> Без `salt_source` детерминированные стратегии используют в качестве сида HMAC-SHA256
> от оригинального значения, ключованный `settings.secret_key`; при указании `salt_source`
> его материал также идёт через HMAC.

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
                ref_entity_column: "ENTITY_ID"
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
> Без `salt_source` стратегии используют как сид HMAC-SHA256 от оригинального значения
> с ключом `settings.secret_key` (соль при наличии — материал тоже идёт через HMAC).

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
