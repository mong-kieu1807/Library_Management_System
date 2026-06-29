# scripts/

Thư mục chứa các CLI script độc lập (không dùng Laravel framework).

---

## list_gemini_models.php

Script dùng để liệt kê toàn bộ Gemini model mà API key hiện tại được phép sử dụng.

### Mục đích

Khi gặp lỗi `404 model not found` hoặc `429 RESOURCE_EXHAUSTED`, dùng script này để biết:

- API key đang dùng hỗ trợ model nào
- Model nào có `generateContent` method
- Model nào đang được active trong `.env`

### Cách chạy

```bash
php scripts/list_gemini_models.php
```

Chạy từ thư mục gốc của project (`Library_Management_System/`).

### Output mẫu

```
API Key  : AQ.Ab8**********Mi5g
Model đang dùng (.env): gemini-2.5-flash
──────────────────────────────────────────────────────────────────────
NAME                                                VERSION          SUPPORTED METHODS
────────────────────────────────────────────────────────────────────────────────────────────────────────
gemini-2.5-flash                                    001              generateContent ◀ active
gemini-2.5-flash-lite                               preview          generateContent
gemini-2.0-flash                                    001              generateContent
...

Tổng: 12 model(s).
```

### Đổi model

Chỉ cần sửa `.env`:

```env
GEMINI_MODEL=gemini-2.0-flash
```

Sau đó chạy:

```bash
php artisan optimize:clear
```

Không cần sửa bất kỳ file PHP nào.
