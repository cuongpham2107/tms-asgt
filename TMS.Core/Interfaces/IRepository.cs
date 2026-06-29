namespace TMS.Core.Interfaces;

public interface IRepository<TEntity, TKey>
    where TEntity : class
{
    IQueryable<TEntity> Query();
    Task<TEntity?> GetByIdAsync(TKey id, CancellationToken ct = default);
    void Add(TEntity entity);
    void Update(TEntity entity);
    void Remove(TEntity entity);
    Task SaveChangesAsync(CancellationToken ct = default);
}
