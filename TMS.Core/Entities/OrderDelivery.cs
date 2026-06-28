namespace TMS.Core.Entities;

public class OrderDelivery
{
    public Guid Id { get; set; }
    public string? RecipientName { get; set; }
    public string DeliveryAddress { get; set; } = string.Empty;
    public DateTime? DeliveredAt { get; set; }
    public string? Notes { get; set; }

    public Guid OrderId { get; set; }
    public Order Order { get; set; } = null!;
}
