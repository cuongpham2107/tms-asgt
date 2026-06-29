using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.OData.Query;
using Microsoft.AspNetCore.OData.Routing.Controllers;
using TMS.Core.Interfaces;

namespace TMS.Api.Controllers;

public abstract class BaseODataController<TEntity, TKey>(
    IRepository<TEntity, TKey> repository)
    : ODataController
    where TEntity : class
{
    [EnableQuery]
    public IActionResult Get()
    {
        return Ok(repository.Query());
    }

    [EnableQuery]
    public async Task<IActionResult> Get([FromRoute] TKey key, CancellationToken ct)
    {
        var entity = await repository.GetByIdAsync(key, ct);
        return entity is null ? NotFound() : Ok(entity);
    }

    public async Task<IActionResult> Post([FromBody] TEntity entity, CancellationToken ct)
    {
        repository.Add(entity);
        await repository.SaveChangesAsync(ct);
        return Created(entity);
    }

    public async Task<IActionResult> Put([FromRoute] TKey key, [FromBody] TEntity entity, CancellationToken ct)
    {
        var existing = await repository.GetByIdAsync(key, ct);
        if (existing is null) return NotFound();
        repository.Update(entity);
        await repository.SaveChangesAsync(ct);
        return Updated(entity);
    }

    public async Task<IActionResult> Delete([FromRoute] TKey key, CancellationToken ct)
    {
        var existing = await repository.GetByIdAsync(key, ct);
        if (existing is null) return NotFound();
        repository.Remove(existing);
        await repository.SaveChangesAsync(ct);
        return NoContent();
    }
}
