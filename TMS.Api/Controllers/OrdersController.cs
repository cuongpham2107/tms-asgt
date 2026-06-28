using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.OData.Query;
using Microsoft.AspNetCore.OData.Routing.Controllers;
using TMS.Application.DTOs;
using TMS.Application.Services;
using TMS.Core.Entities;
using TMS.Core.Interfaces;

namespace TMS.Api.Controllers;

public class OrdersController(
    IOrderRepository repo,
    IOrderService orderService) : ODataController
{
    [EnableQuery]
    public IActionResult Get()
    {
        return Ok(repo.Query());
    }

    [EnableQuery]
    public async Task<IActionResult> Get([FromRoute] Guid key, CancellationToken ct)
    {
        var order = await repo.GetByIdAsync(key, ct);
        return order is null ? NotFound() : Ok(order);
    }

    public async Task<IActionResult> Post([FromBody] Order order, CancellationToken ct)
    {
        repo.Add(order);
        await repo.SaveChangesAsync(ct);
        return Created(order);
    }

    [HttpPost("odata/orders/create-with-trip")]
    public async Task<IActionResult> CreateWithTrip([FromBody] CreateOrderWithTripRequest request, CancellationToken ct)
    {
        var order = await orderService.CreateOrderWithTripAsync(request, ct);
        return Created(order);
    }

    public async Task<IActionResult> Put([FromRoute] Guid key, [FromBody] Order updated, CancellationToken ct)
    {
        var existing = await repo.GetByIdAsync(key, ct);
        if (existing is null) return NotFound();
        repo.Update(updated);
        await repo.SaveChangesAsync(ct);
        return Updated(updated);
    }

    public async Task<IActionResult> Delete([FromRoute] Guid key, CancellationToken ct)
    {
        var existing = await repo.GetByIdAsync(key, ct);
        if (existing is null) return NotFound();
        repo.Remove(existing);
        await repo.SaveChangesAsync(ct);
        return NoContent();
    }
}
