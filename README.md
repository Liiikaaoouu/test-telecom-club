# Test-Telecom-Club — «Daily Pass» (суточная подписка с оплатой с баланса абонента)

Реализация тестового задания carrier-billing на PHP 8.2+: сервис подписки (`service/`)
плюс мок Carrier Platform (`platform-mock/`), скреплённые одной командой
`docker compose up`. Без Composer, без фреймворков, без рантайм-зависимостей —
только ядро PHP + `pdo_sqlite` + `curl`, всё включено в официальные Docker-образы
`php`.

```
docker compose up --build        # вот и всё — одна команда, без ручных шагов
```

UI сервиса: обычный JSON HTTP на **:8080**, мок платформы на **:9100**
(включая хелперы `/admin/*` для прогона сценариев с отказами).

---

## Проход по сценариям (curl)

Терминал A: `docker compose up`. Терминал B:

```bash
MSISDN=tel:1000000001
SVC=http://localhost:8080
MOCK=http://localhost:9100
```

### 1. Подключение, opt-in (F1)
```bash
curl -sX POST $SVC/opt-in -d '{"msisdn":"'$MSISDN'"}'
# → {"ok":true,"status":"ACTIVE"}
# повторный opt-in активного: {"ok":true,"alreadyActive":true}; гонка opt-in'ов — 409,
# платформе ровно один action:"1" (атомарный claim)
curl -s $MOCK/admin/sms          # приветственное SMS видно в моке
```

### 2. Дневное списание — успех + доказательство «списание один раз» (F3)
```bash
curl -sX POST $SVC/jobs/daily-charge            # {"charged":1,...}
curl -sX POST $SVC/jobs/daily-charge            # {"charged":0,"skipped":1,...}
curl -s $MOCK/admin/debits | jq '.successfulCount'   # → 1  (ровно одно дебетование за всё время)
curl -s "$SVC/status?subscriber=$MSISDN"        # SUCCESS + referenceId
curl -s $MOCK/admin/sms                          # в SMS-квитанции есть referenceId
```

### 3. Недостаточно средств → грация/ретрай (E1326)
```bash
curl -sX POST $MOCK/admin/mode -d '{"status":"E1326","subscriberId":"'$MSISDN'"}'
# запускаем для НОВОГО дня, чтобы строка реестра была свежей (сегодняшняя уже зарезервирована):
curl -sX POST $SVC/jobs/daily-charge -d '{"day":"2099-01-02"}'   # → {"graced":1}
curl -s "$SVC/status?subscriber=$MSISDN"                          # chargeToday.status=GRACE + nextRetryAt
curl -sX POST $MOCK/admin/mode -d '{"status":"S1000","subscriberId":"'$MSISDN'"}'
sleep 65   # первый интервал грации (GRACE_RETRY_INTERVALS_SEC=120,...); пользователь пополнил счёт
curl -sX POST $SVC/jobs/daily-charge -d '{"day":"2099-01-02"}'   # → списано, ТЕМ ЖЕ externalTrxId
```

### 4. Перманентная ошибка → suspend (E1308) — обрабатывается иначе
```bash
curl -sX POST $MOCK/admin/mode -d '{"status":"E1308","subscriberId":"'$MSISDN'"}'
curl -sX POST $SVC/jobs/daily-charge -d '{"day":"2099-01-03"}'   # → {"suspended":1}
curl -s "$SVC/status?subscriber=$MSISDN"                          # status=SUSPENDED
curl -sX POST $MOCK/admin/mode -d '{"status":"S1000"}'
# дальнейшие запуски никогда не трогают приостановленного абонента:
curl -sX POST $SVC/jobs/daily-charge -d '{"day":"2099-01-04"}'   # → charged:0
```

### 5. Дублированное уведомление о подписке (F2)
```bash
curl -sX POST $MOCK/admin/notify -d '{"subscriberId":"tel:1000000009","status":"REGISTERED"}' \
  | tee /tmp/notif.json | jq .webhookReply          # → {"statusCode":"S1000",...}
# повторно доставить ТОЧНО то же событие (тот же timeStamp → тот же ключ дедупликации):
jq .payload /tmp/notif.json | curl -sX POST $SVC/webhooks/subscription-notification -d @- \
  | jq                                                # → снова S1000, состояние не изменилось, SMS нет
```

### 6. Отключение сообщением STOP (F4)
```bash
curl -sX POST $MOCK/admin/mo -d '{"sourceAddress":"'$MSISDN'","message":"STOP"}'
curl -s "$SVC/status?subscriber=$MSISDN"            # status=INACTIVE
curl -sX POST $SVC/jobs/daily-charge                # → больше никогда не списывается
curl -s $MOCK/admin/subscriptions                   # платформа получила action:"0" один раз
```

### 7. Финансовый аудит — «было ли списание с X сегодня и какой reference?»
```bash
curl -s "$SVC/admin/charges?subscriber=$MSISDN" | jq '.charges[0] | {status,reference_id,attempt_count}'
```

---

## Как деньги остаются в безопасности

Два независимых слоя, оба доказаны в `service/tests/`:

1. **Резервирование в реестре (наша сторона).** У таблицы `charges` есть
   `UNIQUE(subscriber_ref, billing_day)`. Первое действие задачи для пары
   абонент/день — атомарный `INSERT ... ON CONFLICT DO NOTHING`, устойчивый к
   рестартам и параллельным воркерам. День, для которого уже есть
   терминальная строка (`SUCCESS`, `FAILED_PERMANENT`, `GRACE_EXHAUSTED`,
   `DUPLICATE`, `NEEDS_REVIEW`, `CANCELLED`), никогда не списывается повторно.

2. **Детерминированный ключ идемпотентности (сторона платформы).**
   `externalTrxId = DP-<yyyymmdd>-<sha1(subscriberRef|billingDay)[0..12]>`.
   Один абонент + один день **всегда** дают один и тот же ключ — для ретраёв,
   повторных запусков и после падения процесса. Реальный гейтвей дедуплицирует
   по нему (`E1337`), поэтому даже полная потеря нашей БД не может привести ко
   второму списанию за уже оплаченный день. При `E1337` сервис никогда
   слепо не повторяет запрос: он сверяет исходный результат (в моке для демо
   есть `GET /admin/debits/:key`; у реального гейтвея был бы API статуса
   транзакций).

**Политика по кодам статусов (§E):** `E1326` → ограниченное число грация-повторов
с backoff (`GRACE_RETRY_INTERVALS_SEC`, тот же ключ); `E1308` → suspend
(неповторяемо); `E1318` → собственный короткий backoff с отдельным бюджетом
(`THROTTLE_RETRY_SEC` × `THROTTLE_MAX_RETRIES`, баланс-грацию не расходует);
`E1337` → сверка; неизвестные коды → `NEEDS_REVIEW`, никогда не авто-ретраются
в неизвестное состояние. Открытые GRACE-строки прошлых дней автоматически
закрываются в `GRACE_EXHAUSTED` — в аудите не «висят» незавершённых дней.

**Гонка с opt-out:** перед КАЖДЫМ дебетованием статус абонента перечитывается
из БД — STOP, пришедший после снятия снапшота задачи, гасит ещё не отправленный
дебет (день закрывается в `CANCELLED`). Дебетование, уже ушедшее на платформу,
записывается даже если STOP приходит «в полёте»; каждый *последующий* запуск
пропускает INACTIVE-абонента. (Списание за сегодня и отмена позже в тот же
день — принятое продуктовое поведение: пасс на текущий день уже потреблён;
см. DECISIONS.md.)

**Идемпотентность webhook:** дедупликация по
`sha1(subscriberId|status|timeStamp|frequency)` через первичный ключ таблицы
`webhook_events`; дубликаты отвечают `S1000` и ничего не меняют. «Поздний»
REGISTERED с новым timeStamp **не реактивирует** INACTIVE/SUSPENDED-абонента:
явное намерение пользователя (STOP) сильнее внеполосного события. MO `STOP`
использует оптимистичный `UPDATE ... WHERE status != 'INACTIVE'`, так что
только первый STOP переключает состояние и вызывает обращение к платформе +
прощальное SMS. Конкурентные opt-in сериализуются атомарным claim-переходом
в статус `OPTING_IN` — платформа получает ровно один `action:"1"`.

**Маскированные id абонентов:** `subscriber_ref` — непрозрачная строка везде:
никогда не парсится, никогда не переформатируется; передаётся как есть в
качестве `subscriberId` и адреса SMS. Поставьте `MSISDN_STRICT=false`, чтобы
принимать стабильные маскированные псевдонимы.

**Секреты:** `APP_ID`/`APP_PASSWORD` — **только** из переменных окружения;
без них сервис и мок отказываются стартовать (fail-fast, дефолтов в коде нет;
docker-compose подставляет demo-значения из env, переопределяемые `.env`).
Пароль подставляется только внутри `PlatformClient`; логгер санитизирует поля
`password`; тесты проверяют, что пароль не появляется в логах обоих процессов.

**Опциональное усиление (по умолчанию выключено, демо работает как раньше):**

- `WEBHOOK_SECRET` — общий секрет входящих вебхуков §B/§C. В контракте подписи нет,
  поэтому обе стороны получают одно значение из env, а мок отправляет его заголовком
  `X-Webhook-Secret`. Пусто — проверка отключена. Сравнение — `hash_equals`.
- `SERVICE_API_TOKEN` — Bearer-токен на операторские и финансовые ручки
  (`POST /jobs/*`, `GET /admin/*`). Пусто — ручки открыты, как в демо.
  Продуктовая точка входа (`/opt-in`), вебхуки, `/status` и `/health` остаются открытыми.

Оба механизма покрыты `security_test.php` (открыто без настройки / 401 с настройкой).

---

## Структура

```
docker-compose.yml          service + platform-mock, healthcheck'и, volumes
service/
  public/router.php         точка входа php -S
  src/                      Config, Validation, Store, ChargeJob, Handlers, ...
  scripts/cron_loop.php     опциональный планировщик (JOB_INTERVAL_MINUTES>0)
  tests/                    PHP-интеграционные тесты: реальные стеки по HTTP
platform-mock/
  public/router.php         контракт §A–§D + /admin/* для прогона сценариев
  src/                      состояние на SQLite, HTTP-хелперы
data/                       файлы SQLite (bind-mounted, переживают рестарты)
```

Эндпоинты (сервис): `POST /opt-in`, `POST /webhooks/subscription-notification`,
`POST /webhooks/mo-sms`, `POST /jobs/daily-charge`, `GET /status?subscriber=…`,
`GET /admin/charges?subscriber=&day=`, `GET /health`.

## Тесты

```bash
# через docker (рекомендуется; харнесс использует /proc и posix_kill — Linux):
docker run --rm -v "$PWD:/app" -w /app/service --network host php:8.2-cli php tests/run.php
# или локальный PHP >= 8.2 с pdo_sqlite, curl, posix (Linux/macOS; на Windows не работает)
php service/tests/run.php
```

Харнесс поднимает РЕАЛЬНЫЙ стек (оба процесса `php -S`, HTTP, SQLite) и
прогоняет его ровно как сценарии выше. Группы:

| Группа | Что доказывает |
|---|---|
| `money_test.php` | «списание один раз» при повторных запусках; дублированные webhook; падение → E1337 → сверка; рестарт процесса |
| `retry_test.php` | ретрай E1326 тем же ключом успешен; бюджет повторов ограничен; suspend при E1308 ≠ грация при E1326; throttle E1318; несверившийся E1337 терминален; ключи уникальны по дням |
| `optout_test.php` | поток STOP; повторные STOP — no-op; **гонка opt-out при дебетовании «в полёте»**; безвредный STOP от неизвестного абонента |
| `security_test.php` | валидация на границе; маскированные псевдонимы end-to-end; отсутствие секретов в логах; fail-fast на конфигурации суммы; **опциональный секрет вебхука и Bearer-токен на `/jobs/*` и `/admin/*`** |

## Соответствие чек-листу приёмки

- [x] `docker compose up` поднимает оба контейнера (healthcheck'и, без ручных шагов)
- [x] opt-in регистрирует + приветственное SMS видно в моке
- [x] дневное списание списывает один раз, хранит транзакцию, квитанция с referenceId
- [x] задача дважды в тот же день → одно списание (money_test №1)
- [x] дублированное уведомление → нет двойного списания (money_test №2)
- [x] E1326 и E1308 обработаны по-разному (retry_test №1–№3)
- [x] STOP отключает; последующие запуски пропускают (optout_test №1/№3)
- [x] нет учётных данных в коде и логах (security_test №3)

## Вне объёма (сознательно — см. DECISIONS.md)

Полноценная аутентификация/SSO (есть только опциональный сервисный токен),
мультивалютность, USSD, DLR, Query Balance, возвраты,
production-инфраструктура, полировка UI. Эндпоинты только для мока (`/admin/*`)
явно не являются частью контракта и используются лишь для прогона сценариев
и демонстрации сверки.
