using TMS.Application.DTOs;
using TMS.Core.Entities;
using TMS.Core.Interfaces;

namespace TMS.Application.Services;

public class OrderService(
    IOrderRepository orderRepo,
    ITripRepository tripRepo) : IOrderService
{
    public async Task<Order> CreateOrderWithTripAsync(CreateOrderWithTripRequest request, CancellationToken ct = default)
    {
        var order = new Order
        {
            Id = Guid.NewGuid(),
            OrderNumber = request.OrderNumber,
            Description = request.Description,
            Origin = request.Origin,
            Destination = request.Destination,
            OrderDeliveries = request.Deliveries.Select(d => new OrderDelivery
            {
                Id = Guid.NewGuid(),
                RecipientName = d.RecipientName,
                DeliveryAddress = d.DeliveryAddress,
                Notes = d.Notes,
            }).ToList()
        };

        var tripId = Guid.NewGuid();
        var trip = new Trip
        {
            Id = tripId,
            TripNumber = request.TripNumber,
            VehicleId = request.VehicleId,
            CheckPoints = order.OrderDeliveries.Select((d, i) => new TripCheckPoint
            {
                Id = Guid.NewGuid(),
                Location = d.DeliveryAddress,
                SequenceNumber = i + 1,
                TripId = tripId,
            }).ToList()
        };

        orderRepo.Add(order);
        tripRepo.Add(trip);
        await orderRepo.SaveChangesAsync(ct);

        return order;
    }
}
