<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Link theo dõi không còn hiệu lực — ASG Vận Tải</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #F3F4F6;
            color: #111827;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            -webkit-font-smoothing: antialiased;
        }

        .card {
            width: 100%;
            max-width: 460px;
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 18px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, .08);
            padding: 32px 24px;
            text-align: center;
        }

        .brand img { height: 42px; width: auto; margin: 0 auto 20px; display: block; }

        .icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: #FEF2F2;
            color: #DC2626;
            font-size: 26px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        h1 { font-size: 20px; font-weight: 800; letter-spacing: -.01em; margin-bottom: 8px; }

        p { font-size: 14px; color: #4B5563; line-height: 1.65; }

        .hint {
            margin-top: 18px;
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 12px;
            color: #6B7280;
            text-align: left;
        }

        .hint strong { color: #374151; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">
            <img src="{{ asset('images/ASG.png') }}" alt="ASG Vận Tải">
        </div>
        <div class="icon">!</div>
        <h1>Link theo dõi không còn hiệu lực</h1>
        <p>
            Đường dẫn này đã hết hạn hoặc không hợp lệ, nên chúng tôi không thể hiển thị hành trình chuyến xe.
        </p>
        <div class="hint">
            <strong>Bạn cần làm gì?</strong><br>
            Vui lòng liên hệ bộ phận điều vận ASG để được gửi link theo dõi mới. Link chỉ có hiệu lực trong thời gian giới hạn kể từ khi được tạo.
        </div>
    </main>
</body>
</html>
