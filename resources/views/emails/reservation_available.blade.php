<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sách đặt trước đã có sẵn</title>
</head>
<body>
    <p>Xin chào {{ $userName }},</p>

    <p>Sách bạn đặt trước đã có sẵn:</p>
    <ul>
        <li><strong>Tên sách:</strong> {{ $bookTitle }}</li>
        <li><strong>Thư viện:</strong> {{ $libraryName }}</li>
        <li><strong>Ngày thông báo:</strong> {{ $notifiedAt }}</li>
        <li><strong>Hạn đến nhận:</strong> {{ $expiresAt }}</li>
    </ul>

    <p>Vui lòng đến thư viện trong vòng 2 ngày để nhận sách. Nếu quá hạn, đặt trước sẽ tự động bị hủy.</p>

    <p>Xin cảm ơn,<br/>Ban quản lý thư viện</p>
</body>
</html>
