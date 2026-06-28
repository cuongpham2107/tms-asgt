namespace TMS.Application.DTOs;

public record CreateOrderWithTripRequest
{
    public string OrderNumber { get; init; } = string.Empty;
    public string? Description { get; init; }
    public string? Origin { get; init; }
    public string? Destination { get; init; }
    public string TripNumber { get; init; } = string.Empty;
    public Guid? VehicleId { get; init; }
    public List<DeliveryRequest> Deliveries { get; init; } = [];
}

public record DeliveryRequest
{
    public string? RecipientName { get; init; }
    public string DeliveryAddress { get; init; } = string.Empty;
    public string? Notes { get; init; }
}
