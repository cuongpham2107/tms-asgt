using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.OData.Query;
using Microsoft.AspNetCore.OData.Routing.Controllers;
using TMS.Core.Entities;
using TMS.Core.Interfaces;

namespace TMS.Api.Controllers;

public class TripsController(ITripRepository repo) : ODataController
{
    [EnableQuery]
    public IActionResult Get()
    {
        return Ok(repo.Query());
    }

    [EnableQuery]
    public async Task<IActionResult> Get([FromRoute] Guid key, CancellationToken ct)
    {
        var trip = await repo.GetByIdAsync(key, ct);
        return trip is null ? NotFound() : Ok(trip);
    }

    public async Task<IActionResult> Post([FromBody] Trip trip, CancellationToken ct)
    {
        repo.Add(trip);
        await repo.SaveChangesAsync(ct);
        return Created(trip);
    }

    public async Task<IActionResult> Put([FromRoute] Guid key, [FromBody] Trip updated, CancellationToken ct)
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
