using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TMS.Core.Entities;

namespace TMS.Infrastructure.Data.Configurations;

public class OrderConfiguration : IEntityTypeConfiguration<Order>
{
    public void Configure(EntityTypeBuilder<Order> builder)
    {
        builder.ToTable("Orders");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).ValueGeneratedNever();
        builder.Property(x => x.OrderNumber).HasMaxLength(50).IsRequired();
        builder.HasIndex(x => x.OrderNumber).IsUnique();
        builder.Property(x => x.Description).HasMaxLength(500);
        builder.Property(x => x.Origin).HasMaxLength(200);
        builder.Property(x => x.Destination).HasMaxLength(200);

        builder.HasMany(x => x.OrderDeliveries)
            .WithOne(x => x.Order)
            .HasForeignKey(x => x.OrderId);
    }
}
