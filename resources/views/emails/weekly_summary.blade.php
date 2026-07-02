<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Báo cáo mượn sách hàng tuần</title>
</head>
<body>
    <p>Xin chào {{ $fullName }},</p>
    <p>Đây là báo cáo tuần của bạn.</p>

    <p><strong>Sách đang mượn:</strong><br>
    {{ $borrowedCount }}</p>

    <p><strong>Hạn trả gần nhất:</strong><br>
    @if ($closestDueDate)
        {{ $closestDueDate }}
    @else
        Bạn hiện không có sách đang mượn.
    @endif
    </p>

    <p><strong>Phí chưa thanh toán:</strong><br>
    {{ $unpaidFine }}</p>

    <p><strong>Các sách sắp đến hạn:</strong></p>
    @if (count($dueSoon) > 0)
        <ul>
            @foreach ($dueSoon as $book)
                <li>{{ $book['title'] }} — hạn {{ $book['due_date'] }}</li>
            @endforeach
        </ul>
    @else
        <p>Không có sách nào sắp đến hạn trong 3 ngày.</p>
    @endif

    <p>Xin vui lòng trả sách đúng hạn.</p>
</body>
</html>
