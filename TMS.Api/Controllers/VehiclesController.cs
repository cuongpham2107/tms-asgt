using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.OData.Query;
using Microsoft.AspNetCore.OData.Routing.Controllers;
using TMS.Core.Entities;
using TMS.Infrastructure.Data;

namespace TMS.Api.Controllers;

public class VehiclesController(AppDbContext db) : ODataController
{
    [EnableQuery]
    public IActionResult Get()
    {
        return Ok(db.Vehicles.AsQueryable());
    }

    [EnableQuery]
    public async Task<IActionResult> Get([FromRoute] Guid key, CancellationToken ct)
    {
        var vehicle = await db.Vehicles.FindAsync([key], ct);
        return vehicle is null ? NotFound() : Ok(vehicle);
    }

    public async Task<IActionResult> Post([FromBody] Vehicle vehicle, CancellationToken ct)
    {
        db.Vehicles.Add(vehicle);
        await db.SaveChangesAsync(ct);
        return Created(vehicle);
    }

    public async Task<IActionResult> Put([FromRoute] Guid key, [FromBody] Vehicle updated, CancellationToken ct)
    {
        var existing = await db.Vehicles.FindAsync([key], ct);
        if (existing is null) return NotFound();
        db.Vehicles.Update(updated);
        await db.SaveChangesAsync(ct);
        return Updated(updated);
    }

    public async Task<IActionResult> Delete([FromRoute] Guid key, CancellationToken ct)
    {
        var existing = await db.Vehicles.FindAsync([key], ct);
        if (existing is null) return NotFound();
        db.Vehicles.Remove(existing);
        await db.SaveChangesAsync(ct);
        return NoContent();
    }
}
