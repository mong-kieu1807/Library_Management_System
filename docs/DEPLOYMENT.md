# Deploy lên production — DigitalOcean App Platform + TiDB Cloud + Spaces + Vercel

Hướng dẫn này dành riêng cho stack thật của dự án Library Management System:
Laravel 12 (PHP 8.2) ở `Project_BE/library-management`, và 2 app frontend
(`frontend/admin` — Vite/React, `frontend/website` — Next.js) ở
`Project_FE/Library_Management_System_FE`.

> **Lưu ý**: file `docs/deployment.md` và `.env.prod.example` / `.env.staging.example`
> / `.env.infra.example` / `nginx/` trong repo frontend là tài liệu của một dự án
> khác (KHPT — Quản lý khóa học phong thủy, backend NestJS, VPS + GitLab CI) bị
> dính nhầm vào repo, **không áp dụng được cho dự án này**. Bỏ qua hoặc xoá,
> đừng làm theo.

## Kiến trúc

```
Vercel (frontend/admin, Vite)     ─┐
Vercel (frontend/website, Next.js)─┼─► DO App Platform (Laravel API, Docker)
                                    │        │
                                    │        ├─► TiDB Cloud (database, ngoài DO)
                                    │        └─► DO Spaces (book covers, avatars)
                                    └─ gọi API qua HTTPS, auth bằng Sanctum
                                       Bearer token (không dùng cookie/CORS
                                       credentials, nên không cần domain chung)
```

Database nằm ngoài DigitalOcean (TiDB Cloud), không phải DO Managed Database —
xem Phần A. Mọi phần khác (App Platform, Spaces, Vercel) không đổi.

Backend chạy 4 component từ **cùng một Docker image** (`Dockerfile` ở root),
chỉ khác lệnh khởi động:

| Component | Loại | Lệnh chạy | Vì sao cần |
|---|---|---|---|
| `api` | Web Service | `entrypoint.sh web` (nginx+php-fpm) | Xử lý HTTP request |
| `queue` | Worker | `php artisan queue:work` | 3 job queue (nhắc trả sách, gửi email, generate report) chạy nền qua `QUEUE_CONNECTION=database` |
| `scheduler` | Worker | `php artisan schedule:work` | 5 lệnh định kỳ trong `routes/console.php` (hết hạn đặt trước, nhắc quá hạn, tổng kết tuần...) — container không có cron của host nên phải chạy vòng lặp này |
| `migrate` | Job (Pre-Deploy) | `php artisan migrate --force` | Chạy migration đúng 1 lần mỗi lần deploy, trước khi release, tránh nhiều instance chạy migrate cùng lúc |

Vì sao **không** dùng Docker cho frontend: Vercel build Next.js/Vite natively
(nhanh hơn, có ISR/edge cho Next.js), Docker chỉ hợp lý cho backend Laravel vì
App Platform cần một image tự chứa nginx+php-fpm.

---

## 0. Chuẩn bị

- Tài khoản [DigitalOcean](https://cloud.digitalocean.com) đã add phương thức thanh toán.
- Tài khoản [Vercel](https://vercel.com), liên kết với GitHub.
- Repo backend (`mong-kieu1807/Library_Management_System`) và frontend
  (`NguyenNguyen2/Library_Management_System_FE`) đã push lên GitHub — cả 2 đã
  có remote sẵn, xem `git remote -v`.
- (Tuỳ chọn nhưng khuyến nghị) cài [`doctl`](https://docs.digitalocean.com/reference/doctl/how-to/install/)
  để thao tác App Platform qua CLI thay vì dashboard.
- Docker Desktop nếu muốn build/test image ở máy local trước (khuyến nghị — máy
  dùng để soạn hướng dẫn này không có Docker nên chưa build-test được image,
  bạn nên tự chạy bước 3 trước khi deploy thật).

Các file mình đã tạo/sửa sẵn trong repo backend, dùng xuyên suốt hướng dẫn này:

- `Dockerfile`, `.dockerignore`, `docker/` (nginx, supervisor, php.ini, entrypoint.sh)
- `docker-compose.yml` — test local
- `.do/app.yaml` — spec mẫu cho App Platform (còn nhiều chỗ `REPLACE_ME`)
- `config/cors.php`, `config/filesystems.php`, `.env.example` — đã sửa để đọc từ env
- `app/Models/Book.php`, `app/Models/User.php` — thêm accessor tự resolve URL ảnh bìa/avatar
- `app/Http/Controllers/{ProfileController,Admin/BookController,Admin/UserController}.php`
  — upload/xoá ảnh qua disk cấu hình được (`MEDIA_DISK`) thay vì hardcode local disk

---

## Phần A — Database: TiDB Cloud (đã deploy sẵn, không dùng DO Managed Database)

Database KHÔNG nằm trên DigitalOcean — dùng cluster TiDB Cloud bạn đã tạo sẵn.
TiDB tương thích giao thức MySQL nên Laravel dùng `DB_CONNECTION=mysql` bình
thường, không cần driver riêng.

**Vì sao việc này an toàn**: `app/Services/BackupService.php` (module Backup &
Restore) chạy `mysqldump` trực tiếp — code đã sẵn 2 chỗ được viết riêng cho TiDB
Cloud (không phải MySQL thường):
- `mysqldump ... --ssl` — TiDB Cloud từ chối kết nối không mã hoá.
- Cố tình **không** dùng `--single-transaction` — TiDB không tương thích hoàn
  toàn SAVEPOINT mà cờ này dùng, dùng `--skip-lock-tables --no-tablespaces` thay thế.

Vì code đã test thật với TiDB Cloud trước đó (theo comment trong file), **không
cần sửa gì thêm** — chỉ cần trỏ đúng biến môi trường.

1. Vào TiDB Cloud dashboard → cluster đã tạo → **Connect**, lấy 5 giá trị:
   `Host` (dạng `gateway01.<region>.prod.aws.tidbcloud.com`), `Port` (thường là
   `4000`, không phải `3306`), `User` (thường có dạng `xxxx.root` — giữ nguyên cả
   phần trước dấu chấm, đó là một phần username thật), `Password`, `Database`.
2. **IP Access List**: TiDB Cloud Serverless mặc định cho phép kết nối từ mọi IP
   (bảo mật dựa vào user/password + bắt buộc TLS, không dựa vào allowlist IP).
   Nếu cluster là **Dedicated** tier hoặc bạn đã bật giới hạn IP thủ công, vào
   **Settings → Networking → IP Access List**, thêm `0.0.0.0/0` tạm thời (App
   Platform không có dải IP outbound cố định trừ khi bạn mua thêm "Static
   Outbound IP" add-on) — chấp nhận được vì kết nối vẫn bắt buộc TLS + password,
   không phải mở database ra public không xác thực.
3. Set các biến môi trường ở Phần D (`DB_HOST`, `DB_PORT=4000`, `DB_DATABASE`,
   `DB_USERNAME`, `DB_PASSWORD` — cả 2 cái sau nên đánh dấu `type: SECRET`).
4. `MYSQL_ATTR_SSL_CA` (PDO, khác với flag `--ssl` của `mysqldump` ở trên): để
   trống trước — cert của TiDB Cloud thường chain tới CA công khai đã có sẵn
   trong system CA bundle của image, nên PDO vẫn kết nối mã hoá được mà không
   cần chỉ định CA riêng. Chỉ điền biến này nếu smoke-test ở Phần F báo lỗi SSL
   handshake khi connect DB.

---

## Phần B — DigitalOcean Spaces (lưu ảnh bìa sách / avatar)

App Platform container không có ổ đĩa bền vững — mỗi lần redeploy hoặc restart,
mọi thứ ghi vào local disk (`storage/app/public`) sẽ mất, và nếu scale >1
instance thì các instance cũng không thấy file của nhau. Mình đã sửa code để
upload đi disk nào cũng được qua biến `MEDIA_DISK` — ở production trỏ nó vào
Spaces (S3-compatible).

1. **Create → Spaces Object Storage**. Chọn region (ví dụ `sgp1`).
2. Đặt tên bucket, ví dụ `library-uploads`. Để **CDN**: bật cũng được (tăng tốc
   độ tải ảnh), không bắt buộc.
3. **File Listing**: để **Restrict File Listing** (không cần public list bucket,
   chỉ cần từng file public-read — Laravel set ACL `public` khi upload qua S3
   driver mặc định).
4. Tạo **Spaces access key**: vào **API → Spaces Keys → Generate New Key**. Đây
   LÀ CẶP KEY RIÊNG cho Spaces (không phải DO account API token) — ghi lại
   `Access Key` + `Secret Key`, secret chỉ hiện 1 lần.
5. Giá trị cần cho biến môi trường ở Phần D:
   ```
   MEDIA_DISK=s3
   AWS_ACCESS_KEY_ID=<access key vừa tạo>
   AWS_SECRET_ACCESS_KEY=<secret key vừa tạo>
   AWS_DEFAULT_REGION=sgp1
   AWS_BUCKET=library-uploads
   AWS_ENDPOINT=https://sgp1.digitaloceanspaces.com
   AWS_URL=https://library-uploads.sgp1.digitaloceanspaces.com
   AWS_USE_PATH_STYLE_ENDPOINT=false
   ```
   (Nếu bật CDN ở bước 2, dùng domain CDN cho `AWS_URL` thay vì domain gốc —
   DO hiển thị domain CDN ngay trong Spaces settings.)

**Dữ liệu cũ**: ảnh bìa/avatar đã upload trước khi đổi sang Spaces (nằm ở
`storage/app/public` local) sẽ không tự động có trên Spaces — nếu có dữ liệu
seed/test cũ cần giữ, tự upload thủ công lên bucket (giữ nguyên path tương đối,
vd `book-covers/xxx.jpg`) trước khi đổi `MEDIA_DISK=s3`. Với đồ án/demo thường
chấp nhận được là bỏ qua, upload lại qua UI sau khi deploy.

---

## Phần C — Build & test Docker image ở local (khuyến nghị làm trước khi deploy)

```bash
cd Project_BE/library-management
cp .env.example .env
# Điền APP_KEY (chạy lệnh dưới rồi dán "base64:..." vào .env)
php artisan key:generate --show

docker compose up --build
```

Đợi image build xong (lần đầu ~2-5 phút tuỳ máy), rồi ở terminal khác:

```bash
docker compose exec app php artisan migrate --force
curl http://localhost:8080/up          # health check — phải trả 200
curl http://localhost:8080/api/v1/books/home
```

Nếu container `app` không lên được, xem log: `docker compose logs app`. Việc
này giúp bắt lỗi (thiếu extension PHP, sai quyền file, nginx config sai...)
trước khi tốn công deploy lên DO. **Mình không có Docker ở máy soạn hướng dẫn
này nên chưa tự build-test được — bạn là người đầu tiên chạy thật, nếu build
lỗi, đọc kỹ message rồi báo lại để mình sửa Dockerfile.**

---

## Phần D — Deploy backend lên DO App Platform

`.do/app.yaml` (tracked trong git) là template an toàn, còn giá trị thật nên
điền vào `.do/app.production.yaml` — **đã tạo sẵn, đã điền tự động mọi giá trị
lấy được từ `.env` local + `git remote`**, git-ignored nên không lo lộ secret
lên GitHub. Còn thiếu `AWS_*` (Phần B), `APP_URL`/`GOOGLE_REDIRECT_URI` (biết
sau khi deploy lần đầu), `CORS_ALLOWED_ORIGINS`/`FRONTEND_URL` (biết sau khi
deploy Vercel ở Phần E) — mỗi chỗ còn thiếu đều ghi rõ lý do (`PENDING_*`) thay
vì `REPLACE_ME` chung chung.

Có thể deploy qua dashboard (dán nội dung `app.production.yaml`) hoặc qua CLI:
```bash
doctl apps create --spec .do/app.production.yaml
```

### D.1 — Tạo app

1. **Apps → Create App → GitHub**, chọn repo `Library_Management_System`
   (backend), branch `main`.
2. App Platform tự phát hiện `Dockerfile` ở root và đề xuất build bằng Docker —
   chọn **Dockerfile** làm build method (không chọn buildpack).
3. Đặt tên component đầu tiên là `api`, type **Web Service**, HTTP port `8080`.
4. Đừng bấm Deploy vội — bấm **Edit Plan / Edit Spec** để sửa trực tiếp bằng
   YAML, dán nội dung từ `.do/app.yaml` (đã có sẵn trong repo) làm điểm bắt đầu,
   rồi thay hết các giá trị `REPLACE_ME`:
   - `github.repo`: đúng `owner/Library_Management_System` của bạn.
   - `APP_KEY`: chạy `php artisan key:generate --show` lấy giá trị `base64:...`.
   - `APP_URL`, `GOOGLE_REDIRECT_URI`: điền sau khi biết domain App Platform cấp
     (dạng `https://library-management-api-xxxxx.ondigitalocean.app`) — deploy
     lần đầu trước, xem domain được cấp, quay lại sửa 2 biến này rồi redeploy.
   - Toàn bộ khối `AWS_*`, `MEDIA_DISK=s3`: giá trị từ Phần B.
   - `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`: giá trị
     TiDB Cloud từ Phần A (không có mục `databases:` cần "attach" như DO Managed
     DB — đây là 5 biến thường, gõ tay).
   - `CORS_ALLOWED_ORIGINS`, `FRONTEND_URL`: domain Vercel ở Phần E (điền sau
     khi có domain, tương tự APP_URL).
   - `MAIL_*`, `GOOGLE_CLIENT_*`, `GEMINI_API_KEY`: lấy từ `.env` local hiện tại
     của bạn (đang chạy dev) hoặc tạo credential mới cho production.
5. **Deploy**. App Platform build Docker image theo `Dockerfile`, chạy job
   `migrate` (pre-deploy) rồi mới start `api` + `queue` + `scheduler`.

### D.2 — Xác nhận App Platform kết nối được TiDB Cloud

Không có bước "Trusted Sources" như DO Managed Database. Nếu job `migrate`
(pre-deploy) fail với lỗi connection timeout/refused, quay lại Phần A bước 2 —
khả năng cao TiDB Cloud đang giới hạn IP Access List và chưa mở cho App Platform.

### D.3 — Sửa lại APP_URL / CORS sau khi biết domain thật

Sau deploy lần đầu, App Platform cấp domain dạng
`https://<app-name>-xxxxx.ondigitalocean.app`. Vào **Settings → App-Level Environment
Variables** (hoặc sửa trong spec), cập nhật `APP_URL` và `GOOGLE_REDIRECT_URI`
cho đúng domain này, rồi **Deploy** lại. Làm tương tự cho `CORS_ALLOWED_ORIGINS`
và `FRONTEND_URL` sau khi có domain Vercel ở Phần E.

### D.4 — Custom domain (tuỳ chọn)

**Settings → Domains → Add Domain**, trỏ CNAME domain của bạn (vd
`api.yourdomain.com`) tới domain App Platform cấp. SSL tự cấp qua Let's Encrypt,
không cần certbot thủ công như setup VPS truyền thống.

---

## Phần E — Deploy frontend lên Vercel

Monorepo dùng Yarn workspaces (`frontend/admin`, `frontend/website`,
`frontend/shared`) — mỗi app deploy thành **1 Vercel project riêng**, cùng trỏ
vào 1 repo GitHub, khác Root Directory.

### E.1 — Deploy `frontend/admin` (Vite/React)

1. **Add New → Project**, import repo `Library_Management_System_FE`.
2. **Root Directory**: `frontend/admin`.
3. Bấm **Edit** cạnh Root Directory, bật **"Include source files outside of the
   Root Directory in the Build"** — **bắt buộc**, vì `@frontend/admin` phụ thuộc
   `@frontend/shared` (workspace:*) nằm ngoài `frontend/admin/`, thiếu bước này
   build sẽ lỗi "module not found @shared/...".
4. Framework Preset: Vercel tự nhận **Vite**. Build Command / Output Directory
   để mặc định (`vite build --mode production` theo `package.json`, output `dist`
   theo `vite.config.js`).
5. **Environment Variables**:
   ```
   VITE_API_URL=https://<domain-backend-DO-App-Platform>/api
   ```
   (đúng theo cách `axiosInstance.ts` đọc `import.meta.env.VITE_API_URL`.)
6. Deploy. `packageManager: yarn@4.9.2` trong `package.json` — Vercel tự nhận
   qua Corepack, không cần cấu hình thêm. Nếu build báo lỗi version Yarn, thêm
   Environment Variable `ENABLE_EXPERIMENTAL_COREPACK=1` rồi redeploy.

### E.2 — Deploy `frontend/website` (Next.js)

1. **Add New → Project** (project thứ 2), cùng repo.
2. **Root Directory**: `frontend/website`, cũng bật **"Include source files
   outside of the Root Directory"** (lý do tương tự — phụ thuộc `@frontend/shared`).
3. Framework Preset: Vercel tự nhận **Next.js**.
4. **Environment Variables**:
   ```
   NEXT_PUBLIC_API_URL=https://<domain-backend-DO-App-Platform>/api
   ```
5. Deploy.

### E.3 — Domain

Mỗi Vercel project có domain `*.vercel.app` miễn phí ngay sau deploy. Muốn gắn
domain riêng: project → **Settings → Domains**.

### E.4 — Quay lại cập nhật CORS ở backend

Sau khi có 2 domain Vercel thật, quay lại Phần D.3, set:
```
CORS_ALLOWED_ORIGINS=https://<domain-admin>.vercel.app,https://<domain-website>.vercel.app
FRONTEND_URL=https://<domain-website>.vercel.app
```
rồi deploy lại backend. Thiếu bước này thì browser sẽ chặn request từ frontend
tới API (CORS error), dù API vẫn hoạt động bình thường khi gọi trực tiếp (Postman/curl).

---

## Phần F — Kiểm tra sau khi deploy (smoke test)

```bash
# Backend health
curl https://<domain-backend>/up

# API thật
curl https://<domain-backend>/api/v1/books/home

# Login thử (thay email/password thật)
curl -X POST https://<domain-backend>/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"...","password":"..."}'
```

Trên trình duyệt: mở admin/website domain, thử login, upload 1 ảnh bìa sách/avatar
— kiểm tra ảnh hiển thị đúng (xác nhận Spaces hoạt động, URL trả về không phải
`127.0.0.1`). Kiểm tra 1 tính năng dùng queue (vd đặt trước sách → email nhắc)
để xác nhận worker `queue` chạy. Backup module (Admin → Backup) để xác nhận
`mysqldump` trong container hoạt động với TiDB Cloud (đây là bước quan trọng
nhất cần test kỹ — TiDB có vài điểm không tương thích 100% với MySQL, xem Phần A).

---

## Phần G — Vận hành

- **Logs**: App Platform → chọn component → tab **Runtime Logs**. `doctl apps
  logs <app-id> --follow` nếu dùng CLI.
- **CI/CD tự động**: `deploy_on_push: true` trong `.do/app.yaml` (và Vercel mặc
  định) nghĩa là mỗi lần push lên `main` sẽ tự deploy lại — không cần thao tác
  gì thêm, khác hẳn setup GitLab CI thủ công trong `docs/deployment.md` cũ (tài
  liệu KHPT, không áp dụng ở đây).
- **Rollback**: App Platform → **Deployments** → chọn bản deploy cũ →
  **Rollback to this deployment**. Vercel tương tự ở tab **Deployments**.
- **Đổi biến môi trường**: sửa trong **Settings → App-Level Environment
  Variables** (hoặc sửa `.do/app.yaml` rồi `doctl apps update`), mỗi lần sửa
  sẽ tự trigger redeploy toàn bộ component dùng biến đó.
- **Backup DB**: dùng tính năng Backup & Restore có sẵn trong app (Admin →
  Backup, gọi `BackupService`, xuất file `.sql` qua `mysqldump`). TiDB Cloud
  cũng có backup tự động ở tầng hạ tầng tuỳ plan (xem tab **Backup** trên TiDB
  Cloud dashboard) — kiểm tra plan hiện tại có bật sẵn không, đừng chỉ dựa vào
  1 lớp duy nhất.

---

## Checklist tổng hợp

- [ ] Có đủ 5 giá trị connection TiDB Cloud (host, port 4000, database, user, password)
- [ ] TiDB Cloud IP Access List đã mở cho App Platform (hoặc xác nhận Serverless
      không giới hạn IP)
- [ ] Spaces bucket + access key tạo xong
- [ ] `docker compose up --build` chạy được ở local, `/up` trả 200
- [ ] App Platform app tạo từ `.do/app.yaml` đã điền đủ `REPLACE_ME`, deploy thành công
- [ ] Migration đã chạy (job `migrate` pre-deploy xanh) — nếu fail, kiểm tra lại IP Access List
- [ ] `APP_URL` / `GOOGLE_REDIRECT_URI` đã cập nhật đúng domain App Platform thật
- [ ] 2 project Vercel (`admin`, `website`) deploy xong, đã bật "include source
      files outside root directory"
- [ ] `VITE_API_URL` / `NEXT_PUBLIC_API_URL` trỏ đúng domain backend
- [ ] `CORS_ALLOWED_ORIGINS` / `FRONTEND_URL` ở backend đã cập nhật đúng domain Vercel thật
- [ ] Smoke test: login, upload ảnh (kiểm tra lên Spaces), 1 flow có queue, backup DB
