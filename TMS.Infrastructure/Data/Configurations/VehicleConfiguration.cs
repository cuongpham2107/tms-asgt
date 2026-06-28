using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TMS.Core.Entities;

namespace TMS.Infrastructure.Data.Configurations;

public class VehicleConfiguration : IEntityTypeConfiguration<Vehicle>
{
    public void Configure(EntityTypeBuilder<Vehicle> builder)
    {
        builder.ToTable("Vehicles");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).ValueGeneratedNever();
        builder.Property(x => x.LicensePlate).HasMaxLength(20).IsRequired();
        builder.HasIndex(x => x.LicensePlate).IsUnique();
        builder.Property(x => x.Model).HasMaxLength(200);
        builder.Property(x => x.DriverName).HasMaxLength(200);
    }
}
