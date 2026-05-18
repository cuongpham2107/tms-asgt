# Check TMS – Lái xe

## Yêu cầu

| STT | Công đoạn | Bước | Mục tiêu | HHHK / Hàng ngoài | Kết quả 1 |
|-----|-----------|------|-----------|-------------------|-----------|
| 1 | Vào ca | Đăng nhập | Ghi nhận thời gian làm việc theo thực tế + GPS | Nhập ID/Pass theo lái xe | YES |
| | | | | Chọn vào ca: ghi nhận thời gian thực có cả GPS | NO |
| | | | | Chọn cả ca / nửa ca ngày / nửa ca đêm | NO |
| | | | | Chọn xe (nhập 4 số cuối để ra gợi ý lựa chọn) | NO |
| | | | | Có bảng thông tin ghi nhận kết quả các mục ở trên cho lái xe xem lại và show luôn Km kết thúc gần nhất trước đó | Yes nhưng chưa đủ |
| | | | | Chọn tiếp tục làm việc | yes |
| | | | | Nút kết thúc ca ở dưới cùng | NO |
| | | | | Nếu chọn kết thúc ca thì cập nhật thời gian kết thúc vào bảng thông tin show ra cho lái xe nhìn lại | NO |
| | Danh mục đơn hàng | | Sắp xếp ưu tiên chuyến có thời gian yêu cầu đóng hàng sớm nhất | Hiển thị các chuyến được điều hành gửi lệnh | NO |
| | | | | Chọn chuyến thực hiện | yes |
| | | | | Bảng chi tiết thông tin đơn hàng để lái xe xem thông tin | Yes nhưng chưa đủ |
| | Thực hiện chuyến | | | Chọn Bắt đầu chuyến | yes |
| | | | | **Thực hiện hoàn thiện các trạng thái của chuyến hàng theo thời gian thực:**<br>1. Khi đến điểm nhận hàng: nhập Km đến điểm nhận hàng (*) và chọn xác nhận đến điểm đóng hàng (có GPS) + Chụp ảnh<br>  - Tự động hiển thị km gần nhất trước đó: lái xe sửa số cuối để tránh sai sót<br>  - Km nhập tại điểm nhận hàng không nhỏ hơn so với km gần nhất trước đó<br>2. Đóng hàng xong thì chọn bắt đầu đi giao hàng + GPS + chụp ảnh<br>3. Đến điểm giao hàng, chọn xác nhận đến điểm giao hàng + GPS + chụp ảnh<br>4. Sau khi giao hàng xong: chọn xác nhận giao hàng xong + Nhập Km (*) + GPS + Chụp ảnh<br>  - Tự động hiển thị km gần nhất trước đó: lái xe sửa số cuối để tránh sai sót<br>  - Km nhập tại điểm kết thúc không nhỏ hơn so với km gần nhất trước đó<br>5. Ghi chú: lái xe tự ghi nhận thông tin đặc biệt (nếu được thì cho ghi âm giọng nói và tự chuyển text vào hệ thống để lái xe ko cần đánh máy)<br>6. Bảng thể hiện thông tin các nội dung của đơn hàng đã được ghi nhận<br>7. Chọn Kết thúc chuyến để kết thúc đơn hàng: tự quay về màn hình danh mục chuyến | Yes nhưng chưa đủ các điều kiện kiểm soát |
| | | | **Nguyên tắc:**<br>- Nguyên tắc 1: 1 xe / 1 đơn hàng chỉ gán được 1 lái. Nếu điều hành gán cho lái khác thì tự động out trên app lái cũ và ghi nhận cho lái mới<br>- Nguyên tắc 2: 1 lái có thể gán nhiều xe / nhiều đơn | **Trường hợp chọn đảo lái:**<br>1. Đảo lái do bàn giao ca cho lái khác để về thì nhập Km và chọn kết thúc ca<br>2. Đảo lái do hàng chưa hạ được: thì vẫn nhập Km kết thúc của xe trước khi chuyển sang đơn mới<br>3. Khi đã chọn đảo lái thì đơn hàng này sẽ không hiển thị trong danh mục chuyến của lái xe nữa mà sẽ do điều hành gán trên web để show vào danh mục của lái mới<br>4. Khi lái khác nhận đơn hàng "đảo lái" thì km bắt đầu của lái mới chính là km kết thúc của lái cũ, lái mới sẽ hoàn thành các bước còn lại của đơn hàng chưa thực hiện | Yes nhưng chưa đủ các điều kiện kiểm soát |
| | | | | Lái xe chỉ làm 1 đơn 1 lần: Nếu chưa hoàn thiện đơn hàng 1 (chưa chọn kết thúc chuyến hoặc đảo lái) thì không vào được đơn hàng 2 để bắt đầu chuyến | NO |
| | Kết thúc ca trực | | Ghi nhận thời gian kết thúc theo thực tế + GPS | Kết thúc ca — tạo thêm nút ở góc phải của phần "Danh sách chuyến": nhập km kết thúc, số km kết thúc không nhỏ hơn km gần nhất trước đó ghi nhận | NO |
| | | | Ghi nhận Km kết thúc | Chọn chuyến tiếp theo nếu có | Yes |
| | | | Tính ra được tổng Km có hàng và không hàng của 1 lái xe (lái xe lái nhiều xe thì cũng tính ra được theo user của lái) | **Ví dụ:**<br>- Lái 1 kết thúc ca nhập Km kết thúc là 5000<br>- Lái 2 vào ca nhận xe thì hiển thị km bắt đầu ca là kết quả của km gần nhất là 5000<br>- Phần có hàng ghi nhận cho lái xe sẽ tính từ lúc nhập Km đến điểm nhận hàng đến khi nhập km kết thúc giao hàng xong<br>- Còn lại là phần km không hàng = tổng km của lái − tổng km có hàng | |

---

## Mục làm sau

| Công đoạn | Mục tiêu | Ghi chú |
|-----------|----------|---------|
| Quản lý tổng thể | Quản lý đơn hàng | Bổ sung thêm tổng km |
| | Danh sách lái trực trong ngày | |
