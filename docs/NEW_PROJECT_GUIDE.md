# Platformadan yangi loyiha boshlash — yo'riqnoma

Bu hujjat Yii3 Enterprise Platform asosida yangi loyiha (masalan, `ob`) ochish uchun qadamma-qadam yo'riqnoma.
Platforma tayyor holda nimalarni beradi, yangi loyihada nimalarni o'zgartirish kerak, biznes modul qanday yoziladi,
qanday tekshiriladi va qanday ishga tushiriladi — barchasi shu yerda. Batafsil texnik hujjatlarga havolalar berilgan.

> Muallif: ShokirjonMK · Platforma versiyasi: `config/common/params.php` → `version`

---

## 0. Platforma tayyor holda nima beradi

| Soha | Tayyor | Hujjat |
|---|---|---|
| Kirish va xavfsizlik | Argon2id parol, bloklash va throttling, 2FA (TOTP, e-mail, passkey, Telegram, SMS, E-IMZO), sessiyalar, step-up, SSO | [SECURITY.md](SECURITY.md), [MFA.md](MFA.md), [SSO.md](SSO.md) |
| Ruxsatlar | Dinamik RBAC (paneldan rol va ruxsat), default deny, tenant izolyatsiyasi | [RBAC.md](RBAC.md) |
| Admin panel | 25+ bo'lim: foydalanuvchilar, tashkilotlar, sozlamalar, feature flag'lar, audit, backup, monitoring, Kafka, Redis, bazalar | [ADMIN_GUIDE.md](ADMIN_GUIDE.md) |
| REST API v1 | Token (password / client_credentials), refresh rotation, scope'lar, pagination, ETag, CORS, rate limit, yopiq Swagger | [API.md](API.md) |
| Asinxron ishlar | Job queue, scheduler (exactly-once), transactional outbox → Kafka → idempotent consumer'lar, DLQ | [WORKERS.md](WORKERS.md), [KAFKA.md](KAFKA.md) |
| Bazalar | Asosiy PostgreSQL + istalgancha qo'shimcha ulanish (PostgreSQL, MySQL/MariaDB, SQLite, SQL Server, Oracle, MongoDB, ClickHouse) | [DATABASES.md](DATABASES.md) |
| Redis | State (`noeviction`) va cache (`allkeys-lfu`) ajratilgan | [REDIS.md](REDIS.md) |
| Kod generatori | Enterprise Gii: bitta buyruq bilan to'liq biznes modul | [GII.md](GII.md) |
| Kuzatuv | JSON log, Prometheus metrikalar, OpenTelemetry, health endpoint'lar | [MONITORING.md](MONITORING.md) |
| Sifat | PHPUnit (unit, integration, api, e2e, security), Psalm, php-cs-fixer, CI (GitHub Actions + GitLab CI) | [TESTING.md](TESTING.md), [CI_CD.md](CI_CD.md) |
| Deploy | Production image'lar, Kubernetes manifestlari, runbook'lar | [DEPLOYMENT.md](DEPLOYMENT.md), [OPERATIONS.md](OPERATIONS.md) |

Namuna (reference) modullar — yangi modul yozganda ko'chirib olinadigan andozalar:

| Modul | Nimani ko'rsatadi |
|---|---|
| `src/Modules/Projects` | Qo'lda yozilgan to'liq modul: lifecycle, a'zolar, outbox event'lar, Kafka read model, fon hisobot |
| `src/Modules/Contracts`, `src/Modules/Blog` | Enterprise Gii generatsiya qilgan modullar (workflow, connector, scheduler, hisobot) |
| `src/Modules/MultiDb` | Bitta CRUD 5 ta bazada: MariaDB, PostgreSQL ×2, MongoDB, ClickHouse — Admin, Web, API, konsol ([DATABASES.md §6](DATABASES.md)) |

---

## 1. Yangi repozitoriy ochish

Platformaning tarixini olib o'tmasdan, toza tarix bilan yangi repo ochish tavsiya etiladi:

```bash
# platforma papkasida
git archive --format=tar HEAD | (mkdir -p ../ob && tar -x -C ../ob)

cd ../ob
git init -b main
git add -A
git commit -m "chore: initial project from Yii3 Enterprise Platform (<platforma commit hash>)"
git remote add origin <yangi repo URL>
git push -u origin main
```

`git archive` faqat commit qilingan fayllarni oladi: `.env`, `vendor/`, `runtime/` va lokal fayllar o'tmaydi. Bu to'g'ri.

Platformadagi keyingi tuzatishlarni yangi loyihaga olish uchun platforma repozitoriysini qo'shimcha remote sifatida
qo'shib, kerakli commit'larni `git cherry-pick` qiling:

```bash
git remote add platform https://github.com/ShokirjonMK/Yii3-Enterprise-Platform.git
git fetch platform
git cherry-pick <commit>
```

---

## 2. Loyiha nomi va identifikatorlari

| Nima | Qayerda | Misol |
|---|---|---|
| Ilova nomi (UI, e-mail, TOTP issuer) | `.env` → `APP_NAME` (`.env.example` ham) | `APP_NAME="OB tizimi"` |
| Docker Compose loyiha nomi | `compose.yaml` → `name: yep` yoki `.env` → `COMPOSE_PROJECT_NAME=ob` | `ob` |
| Portlar (bitta kompyuterda ikki loyiha bo'lsa) | `.env` → `HTTP_PORT`, `PG_PORT`, `MAILPIT_UI_PORT` | `8081`, `55433`, `8026` |
| Sessiya cookie nomi | `config/common/params.php` → `yep_sid` | `ob_sid` |
| Repozitoriy havolasi, muallif | `config/common/params.php` → `repository`, `author` | |
| Composer paket nomi | `composer.json` → `name`, `description` | `company/ob` |
| README | `README.md` | loyiha tavsifi |

Namespace (`App\…`) o'zgarmaydi. Uni o'zgartirish ko'p ish talab qiladi va hech qanday foyda bermaydi.

---

## 3. Birinchi ishga tushirish

Talablar: Docker Desktop (Windows'da WSL2 backend), Git Bash. PHP lokal o'rnatilishi shart emas.
To'liq ma'lumot: [LOCAL_SETUP.md](LOCAL_SETUP.md).

```bash
./scripts/platform.sh init       # .env ni tasodifiy maxfiy qiymatlar bilan yaratadi (idempotent)
./scripts/platform.sh build      # yep-php:dev image
./scripts/platform.sh up         # asosiy servislar
./scripts/platform.sh install    # composer install + ./yii platform:install (migratsiya, RBAC, seed, birinchi superadmin)
```

- `install` superadmin uchun bir martalik parolni chiqaradi. Birinchi kirishda parolni almashtirish va 2FA ni yoqish majburiy.
- Qo'shimcha bazalar kerak bo'lsa: `./scripts/platform.sh up databases`.
- Monitoring kerak bo'lsa: `./scripts/platform.sh up monitoring`.
- Kafka topic'lari: `./scripts/platform.sh kafka-provision`.
- Har bir admin bo'limi uchun demo ma'lumot: `./scripts/platform.sh demo` (faqat dev/test).

Tekshirish: `http://localhost:<HTTP_PORT>/admin` (panel), `/health/ready`, `/api/docs`.
`/api/docs` ga kirish uchun `api.docs.view` ruxsati yoki superadmin bergan parol kerak.

---

## 4. Keraksiz namuna modullarni o'chirish

Namuna modullarni tezda **o'chirib qo'yish** mumkin, kodni **olib tashlash** esa toza yo'l.

**Vaqtincha o'chirish (kod qoladi):** Admin → System Settings → Feature flags → `module.projects`, `module.blog`,
`module.contracts`, `module.multidb` → *Disabled*. Shundan keyin ularning menyusi yashiriladi va route'lari 404 qaytaradi.

**Butunlay olib tashlash:**

1. Migratsiyani orqaga qaytaring yoki yangi loyihada umuman qo'llamang.
2. `src/Modules/<Name>/` papkasini o'chiring. Route, DI, RBAC va migratsiyalar glob orqali topiladi, shuning uchun boshqa fayllarni tahrirlash shart emas.
3. Modulning testlarini o'chiring: `tests/*/<Name>*Test.php`, Gii testlari (`GiiGeneratedModuleTest` Contracts'dan foydalanadi), `tests/Api/Api<Name>Test.php`.
4. Menyu va havolalarni olib tashlang:
   - `src/Admin/Menu/AdminMenu.php` → "Business modules" guruhi;
   - `resources/views/layouts/web.php` → yuqori menyu;
   - `Projects` o'chirilsa, `src/Console/Command/DemoCommand.php` va `src/Api/V1/Auth/AuthController.php::SCOPES`.
5. `./yii rbac:sync`, `./yii api:openapi`, so'ng testlar.

Tavsiya: birinchi bosqichda `Projects` va `MultiDb` modullarini andoza sifatida qoldiring, flag bilan o'chirib qo'ying.
O'z modulingiz tayyor bo'lgach, ularni olib tashlang.

---

## 5. O'z biznes modulingizni yozish

### 5.1 Enterprise Gii bilan (tavsiya etiladi)

```bash
# ko'rib chiqish (fayllar yozilmaydi)
docker compose exec -T app php yii gii:module Requests \
  --fields='title:string:required:max=255,amount:decimal:min=0,status:enum(draft|active|closed),due_on:date,owner_id:user'

# yozish
docker compose exec -T app php yii gii:module Requests --fields='…' --apply
docker compose exec -T app php yii migrate:up && docker compose exec -T app php yii rbac:sync
docker compose exec -T app php yii kafka:provision
```

Gii quyidagilarni yaratadi:
- domain va lifecycle;
- service, repository, migratsiya;
- Admin, Web va API qismlari (OpenAPI bilan);
- RBAC, outbox event'lar, consumer, fon hisobot va testlar.

Shu qadamlarni paneldan ham bajarish mumkin: Developer Tools → Gii → Next steps.
Batafsil: [GII.md](GII.md).

### 5.2 Qo'lda yozish

Har bir modul `src/Modules/<Name>/` papkasida turadi. Tuzilma:

```
src/Modules/<Name>/
  module.php                    manifest: code, name, flag = module.<code>, admin_route, web_route, api_prefix
  Domain/                       entity, enum'lar, qoidalar (framework'ga bog'liq emas)
  Application/<Name>Service.php barcha biznes qoidalar, ruxsat tekshiruvi, audit, outbox — bitta tranzaksiyada
  Infrastructure/               repository, job'lar, consumer'lar, tashqi tizim adapterlari
  Admin/  Web/  Api/            har birida: Controller + routes.php + views/
  config/{di,permissions,jobs,events,commands}.php
  migrations/M<yymmddhhmmss><Name>.php
```

Qoidalar ([AGENTS.md](../AGENTS.md), [MODULES.md](MODULES.md), [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md)):
- Controller yupqa bo'ladi: kirishni o'qiydi, servisni chaqiradi va `Responder` orqali javob qaytaradi. Biznes qoida faqat servisda yoziladi.
- Har bir route `RequirePermission` (web) yoki `RequireApiPermission` (API) bilan yopiladi va `RequireFeature::for('module.<code>')` ostida turadi.
- Har bir o'zgarish `AuditLogger::record(...)` orqali auditga yoziladi. Event `Outbox::record(...)` bilan shu tranzaksiya ichida yoziladi.
- `ValidationException` formada 422 bilan qayta chiziladi, API'da esa `validation_failed` qaytaradi.
- CSP qat'iy: inline `<script>`, `style=""` va `on*=` ishlatilmaydi. Faqat `assets/admin/app.css` klasslari.
- Matnlar inglizcha yoziladi va `$h->t()` orqali o'tkaziladi. Tarjimalar `resources/messages/{uz,ru}/app.php` da.
- Maxfiy qiymatlar kodga, logga va auditga yozilmaydi: `Encrypter` yoki `secret` turidagi sozlamadan foydalaning.

### 5.3 Qo'shimcha bazalar bilan ishlash

1. Ulanishni e'lon qiling, buning ikki yo'li bor:
   - `.env` orqali:
     ```
     DB_CONNECTIONS=crm
     DB_CONN_CRM_URL=mysql://user:${CRM_DB_PASSWORD}@crm-db:3306/crm
     ```
   - **Admin → Databases → Add connection** orqali. Ulanish saqlashdan oldin tekshiriladi, parol shifrlangan holda saqlanadi.
2. Kodda ishlatish:
   ```php
   public function __construct(private DatabaseManager $dbs) {}

   $rows = (new Query($this->dbs->sql('crm')))->from('clients')->where(['active' => 1])->all();
   $this->dbs->mongo('docs')->selectCollection('files')->insertOne([...]);
   $this->dbs->clickhouse('events')->insert('page_views', $rows);
   ```
3. Tayyor andoza `src/Modules/MultiDb` da. Bitta servis bir nechta engine bo'yicha store'larga bo'lingan: `SqlDatasetStore`, `MongoDatasetStore`, `ClickHouseDatasetStore`.
   ```bash
   ./yii multidb:install                                   # jadvallar va namuna ma'lumotlar
   ./yii multidb:crud products list --q=pro --sort=-price  # konsoldan CRUD
   ```
4. Platforma migratori faqat asosiy bazani boshqaradi. Boshqa bazalardagi jadvallar `MultiDb` modulidagi kabi idempotent install buyrug'i bilan yaratiladi, yoki o'sha bazaning o'z migratsiya vositasidan foydalaniladi.
5. Ikki xil bazaga yozish bitta tranzaksiya bo'lmaydi. Ishonchli bo'lishi kerak bo'lsa, outbox va consumer orqali qiling: asosiy bazaga yoziladi, consumer ikkinchi bazani yangilaydi.

Batafsil: [DATABASES.md](DATABASES.md).

### 5.4 API

- Resurs `src/Modules/<Name>/Api/` da yoziladi. Controller'ga OpenAPI atributlari qo'yiladi, `routes.php` da `BearerAuthentication`, `RequireFeature` va `RequireApiPermission::for('<perm>', '<scope>')` beriladi.
- Yangi scope'lar `src/Api/V1/Auth/AuthController.php::SCOPES` ga qo'shiladi.
- Hujjatni yangilash: `./yii api:openapi`. Fayl `runtime/api/openapi.json` ga yoziladi va ochiq emas.
- Swagger'ga kirish ikki yo'l bilan beriladi:
  - `api.docs.view` ruxsati — developer va admin rollarida bor;
  - **Admin → API Management → Docs access** da superadmin yaratgan muddatli parol. Parol bir marta ko'rsatiladi va istalgan vaqtda bekor qilinadi.
- Tashqi tizimlar uchun: **Admin → API Management → Clients**, `client_credentials` grant. Foydalanuvchilar uchun: shaxsiy tokenlar.

Batafsil: [API.md](API.md).

---

## 6. Rollar va kirish

| Rol | Kim uchun |
|---|---|
| `superadmin` | Hamma narsa (`*`), jumladan break-glass amallari. Kamida bitta faol superadmin doim qoladi |
| `admin` | Barcha admin bo'limlari (break-glass'dan tashqari) |
| `business_admin` | Biznes modullarni to'liq boshqaradi |
| `developer` | Developer Tools, Gii, API hujjatlari, modullarni o'qish va tahrirlash |
| `auditor` | Audit va loglarni faqat o'qish |
| `user` | Oddiy foydalanuvchi: Web qism, modullarni ko'rish |

Rol va ruxsatlar paneldan boshqariladi: Admin → RBAC & Access Control. Modulning `config/permissions.php` fayli `./yii rbac:sync` bilan
qo'shiladi. Paneldan kiritilgan o'zgarishlar sync paytida saqlanib qoladi. 2FA siyosati har bir rol uchun alohida: Admin → Authentication → MFA.

---

## 7. Sinov (har bir o'zgarishdan keyin)

```bash
./scripts/platform.sh test --testsuite unit,integration          # test bazasi platform_test
docker compose exec -T app php vendor/bin/phpunit --testsuite api,security
docker compose exec -T app php vendor/bin/phpunit --testsuite e2e   # ko'p xotira oladi; kerak bo'lsa fayl bo'yicha
./scripts/platform.sh psalm                                      # --threads=1, baseline bilan
./scripts/platform.sh cs                                         # php-cs-fixer
docker compose exec -T app composer audit
```

- Har bir yangi modul uchun kamida uch xil test yozing: servis uchun integration test, API test va UI uchun E2E test. Andoza: `tests/*/MultiDb*Test.php` yoki `tests/*/Blog*Test.php`.
- CI: `.github/workflows/ci.yml` va `.gitlab-ci.yml` tayyor. GitHub'da Actions yoqilgan bo'lishi kerak: Settings → Actions → General.

Batafsil: [TESTING.md](TESTING.md), [CI_CD.md](CI_CD.md).

---

## 8. Production'ga chiqarish

1. Production'da `.env` fayli ishlatilmaydi. Barcha qiymatlar Kubernetes Secret, GitLab protected variable yoki secret manager orqali beriladi: `APP_ENV=prod`, `APP_DEBUG=false`, DB, Redis, Kafka, S3, SMTP va kalitlar.
2. Image'lar: `make prod-build`. Kubernetes: `make k8s-render ARGS=production` va `scripts/ci/k8s-validate.sh`.
3. Deploy tartibi: migratsiya (`./yii migrate:up`), `./yii rbac:sync`, `./yii kafka:provision`, so'ng yangi pod'lar.
4. Ishga tushirgandan keyingi tekshiruvlar:
   - `/health/ready` ishlaydi;
   - Admin → Monitoring ma'lumot ko'rsatadi;
   - Admin → Backup'da birinchi zaxira nusxa olingan va tiklash tekshirilgan;
   - Admin → Databases'da barcha ulanishlar OK.
5. Developer Tools va Gii production'da avtomatik o'chadi.

Batafsil: [DEPLOYMENT.md](DEPLOYMENT.md), [BACKUP_RECOVERY.md](BACKUP_RECOVERY.md), [OPERATIONS.md](OPERATIONS.md), `docs/runbooks/`.

---

## 9. Yangi loyiha uchun tekshiruv ro'yxati

- [ ] Yangi repo toza tarix bilan ochildi, `platform` remote qo'shildi
- [ ] `APP_NAME`, compose loyiha nomi, portlar, cookie nomi, `composer.json`, README o'zgartirildi
- [ ] `init → build → up → install` o'tdi, superadmin parolni almashtirdi va 2FA yoqdi
- [ ] Keraksiz namuna modullar flag bilan o'chirildi yoki olib tashlandi
- [ ] Birinchi biznes modul Gii bilan yaratildi, `migrate:up` va `rbac:sync` bajarildi
- [ ] Kerakli qo'shimcha bazalar ulandi (Admin → Databases da OK)
- [ ] Rollar (RBAC & Access Control) va 2FA siyosati (Authentication) sozlandi, tashkilotlar va foydalanuvchilar qo'shildi
- [ ] API client'lar yaratildi, Swagger'ga kirish berildi (ruxsat yoki parol)
- [ ] Unit, integration, API, security va E2E testlar, Psalm va cs-fixer o'tdi
- [ ] CI yoqildi (GitHub Actions / GitLab CI)
- [ ] Production secret'lari secret manager'da, backup va monitoring tekshirildi
