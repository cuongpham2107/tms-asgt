using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TMS.Core.Entities;

namespace TMS.Infrastructure.Data.Configurations;

public class OrderDeliveryConfiguration : IEntityTypeConfiguration<OrderDelivery>
{
    public void Configure(EntityTypeBuilder<OrderDelivery> builder)
    {
        builder.ToTable("OrderDeliveries");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).ValueGeneratedNever();
        builder.Property(x => x.RecipientName).HasMaxLength(200);
        builder.Property(x => x.DeliveryAddress).HasMaxLength(500).IsRequired();
        builder.Property(x => x.Notes).HasMaxLength(1000);
    }
}
