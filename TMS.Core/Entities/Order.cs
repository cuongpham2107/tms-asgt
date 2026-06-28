namespace TMS.Core.Entities;

public class Order
{
    public Guid Id { get; set; }
    public string OrderNumber { get; set; } = string.Empty;
    public string? Description { get; set; }
    public string? Origin { get; set; }
    public string? Destination { get; set; }

    public ICollection<OrderDelivery> OrderDeliveries { get; set; } = [];
}
