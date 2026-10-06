<div align="center">
  <img src="https://cdn.jsdelivr.net/gh/devicons/devicon@latest/icons/laravel/laravel-original.svg" alt="Laravel" width="82" height="82">

  # QuiQue Micromarket

  **Sistema web de gestión comercial, inventario y punto de venta para micromercados.**

  [![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
  [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://www.php.net)
  [![MySQL](https://img.shields.io/badge/MySQL%20%2F%20MariaDB-00618A?logo=mysql&logoColor=white)](https://www.mysql.com)
  [![Tests](https://img.shields.io/badge/tests-185%20passed-22C55E?logo=phpunit&logoColor=white)](#pruebas-automatizadas)
</div>

---

## Sobre el proyecto

QuiQue Micromarket centraliza la operación diaria de un comercio en una aplicación web: catálogo, existencias, ventas, clientes, sorteos comerciales, reportes y tareas administrativas. La información se separa por sucursal y las acciones disponibles se controlan según el rol del usuario.

El sistema está orientado a conservar trazabilidad. Las ventas guardan los datos históricos de cada línea, los cambios de precio generan un historial inmutable, todo ajuste de stock produce un movimiento y las anulaciones revierten existencias sin eliminar la operación original.

## Características principales

- Punto de venta con búsqueda por nombre, código de barras o código interno.
- Carrito persistente en sesión, actualización de cantidades y control de cambios de precio antes de confirmar.
- Cobros en efectivo, QR o modalidad mixta, incluido cálculo de cambio para efectivo.
- Comprobantes térmicos e historial detallado de ventas.
- Anulación de ventas del día con motivo obligatorio, restitución transaccional del stock y registro de auditoría.
- Inventario con entradas, salidas y ajustes positivos o negativos; no permite existencias negativas.
- Alertas por stock agotado, stock mínimo, productos próximos a vencer y productos vencidos.
- Productos por unidad, kilogramo o litro, con precisión acorde a cada unidad comercial.
- Gestión de clientes y emisión de tickets para sorteos por umbral configurable.
- Dashboard operativo, reportes por períodos y exportaciones en PDF.
- Administración de cajeros, control de cuentas activas y aislamiento de datos por sucursal.
- Respaldos locales de MySQL manuales y automáticos en formato comprimido.
- Interfaz adaptable con modos claro y oscuro.

## Módulos del sistema

| Módulo | Alcance implementado |
| --- | --- |
| **Dashboard** | Ventas confirmadas y anuladas del día, totales por forma de pago, operaciones mixtas, productos más vendidos y alertas de abastecimiento y vencimiento. Permite exportar el resumen de inventario a PDF. |
| **Punto de venta** | Búsqueda y lectura de códigos, carrito en sesión, validación de disponibilidad y precio, cantidades enteras o decimales según unidad, pagos en efectivo, QR y mixtos, y comprobante térmico. |
| **Ventas** | Historial y detalle con datos históricos, pagos asociados, recibo y anulación de operaciones del día. No existen rutas para editar o eliminar ventas confirmadas. |
| **Productos** | Alta, consulta, filtros, edición y activación/desactivación. Maneja categoría, unidad, precios de compra y venta, stock mínimo, vencimiento, código de barras o código interno generado. |
| **Categorías** | Búsqueda, creación, edición y activación/desactivación sin borrado físico. Los nombres son únicos por sucursal después de su normalización. |
| **Inventario** | Consulta y filtros por categoría, estado, nivel de stock y vencimiento. Registra movimientos manuales y automáticos con stock anterior y resultante, motivo, observación, usuario, sucursal y fecha. |
| **Clientes y sorteos** | Búsqueda por nombre, teléfono o CI; alta de clientes durante la venta; tickets por múltiplos completos del umbral; períodos bimestrales y estados activo, expirado o anulado. |
| **Reportes** | Indicadores, detalle de ventas, productos vendidos y gráficos por hora o día para hoy, ayer, una fecha, un rango o un mes. El administrador puede descargarlos en PDF. |
| **Usuarios** | Creación, edición, activación/desactivación y restablecimiento de contraseña de cajeros. El alta pública está deshabilitada. |
| **Auditoría** | Registro por sucursal de acciones sensibles sobre productos y anulaciones, con valores anteriores y nuevos sin exponer datos sensibles. |
| **Respaldos** | Volcado completo de MySQL mediante `mysqldump`, compresión Gzip, control de concurrencia y seguimiento del último respaldo correcto. |

## Flujo de una venta

1. El usuario localiza productos disponibles por texto, código o lectura de código de barras.
2. El carrito se conserva en la sesión y vuelve a consultar los datos vigentes desde la base de datos.
3. Antes del cobro, el backend valida sucursal, estado del producto, unidad, stock y precio actual.
4. La venta, sus líneas, pagos y movimientos de inventario se crean dentro de una transacción.
5. Si el total alcanza el umbral del sorteo, se solicita aceptar o rechazar la participación; al aceptar se vincula un cliente y se generan los tickets correspondientes.
6. La operación queda disponible en el historial y puede imprimirse como comprobante térmico.

Si una venta se anula durante el mismo día local, el sistema restaura el stock, crea movimientos de reversión, invalida sus tickets de sorteo y conserva la venta como registro histórico.

## Roles y permisos

La aplicación implementa dos roles:

- **Administrador:** gestiona categorías y cajeros, consulta auditoría, configura el umbral de sorteos, ejecuta respaldos manuales y exporta reportes comerciales en PDF.
- **Cajero:** opera productos dentro de los campos autorizados, inventario, POS, ventas, clientes y consultas de reportes.

Ambos roles pueden trabajar con ventas e inventario de su sucursal. Las políticas, middleware y validaciones de backend impiden operar sobre datos de otra sucursal; las cuentas inactivas pierden el acceso incluso si ya tenían una sesión abierta.

## Reglas de negocio y trazabilidad

- El stock solo cambia mediante movimientos de inventario y cada actualización utiliza bloqueo y transacción de base de datos.
- Los productos por unidad aceptan cantidades enteras; kilogramo y litro admiten hasta tres decimales.
- Los precios utilizan dos decimales y sus modificaciones quedan en `product_price_history` con usuario y sucursal.
- Un código de barras activo no puede repetirse dentro de una misma sucursal.
- Los datos históricos de una venta —nombre, unidad, cantidad y precio— no dependen de cambios posteriores en el producto.
- Las ventas anuladas no se incluyen en ingresos, gráficos ni métricas de ventas confirmadas.
- Los tickets de sorteo se calculan por venta, no se acumulan importes entre operaciones, y se agrupan en períodos bimestrales.
- La aplicación opera con la zona horaria `America/La_Paz`.

## Tecnologías utilizadas

| Área | Tecnologías |
| --- | --- |
| Backend | PHP 8.2+, Laravel 12 |
| Interfaz | Blade, Tailwind CSS 3, Alpine.js, JavaScript |
| Persistencia | MySQL/MariaDB, Eloquent ORM, migraciones y seeders |
| Construcción de assets | Vite 7, PostCSS, Autoprefixer |
| PDF | DOMPDF mediante `barryvdh/laravel-dompdf` |
| Códigos de barras | ZXing Browser |
| Pruebas | PHPUnit 11 y utilidades de testing de Laravel |

## Arquitectura y estructura

La solución sigue la estructura MVC de Laravel y separa las reglas operativas de los controladores:

```text
app/
├── Contracts/          # Contratos para infraestructura de respaldo
├── Data/               # Objetos de resultado
├── Enums/              # Estados, roles, unidades y tipos de movimiento
├── Http/
│   ├── Controllers/    # Endpoints web y módulos administrativos
│   ├── Middleware/     # Cuenta activa y autorización por rol
│   └── Requests/       # Validación y autorización de entradas
├── Models/             # Entidades y relaciones Eloquent
├── Policies/           # Permisos por recurso, rol y sucursal
└── Services/           # Ventas, inventario, reportes, sorteos, auditoría y backups

database/
├── factories/          # Datos para pruebas
├── migrations/         # Esquema y evolución de la base de datos
└── seeders/            # Roles y sucursal inicial

resources/
├── css/                # Estilos Tailwind
├── js/                 # Interacciones y escáner de códigos
└── views/              # Vistas Blade y plantillas PDF

tests/
├── Feature/            # Flujos HTTP, permisos e integración
└── Unit/               # Reglas aisladas
```

Servicios como `SaleService`, `InventoryService` y `SaleCancellationService` encapsulan operaciones transaccionales. `ReportService` y `DashboardService` concentran las consultas de indicadores; las policies y Form Requests aplican autorización y validación fuera de la interfaz.

## Persistencia de datos

El modelo relacional incluye sucursales, roles y usuarios; categorías y productos; historial de precios; ventas, líneas y pagos; movimientos de inventario; clientes, participaciones, períodos y tickets de sorteo; auditoría y configuración de respaldos.

Las claves foráneas protegen el historial mediante restricciones de borrado. Las entidades operativas incluyen `branch_id`, lo que permite mantener el alcance de cada consulta y operación dentro de la sucursal correspondiente.

## Pruebas automatizadas

La suite cubre autenticación, roles, gestión de usuarios, productos y categorías, inventario, POS, pagos, anulaciones, clientes, sorteos, dashboard, reportes, auditoría, respaldos y exportaciones PDF.

Estado verificado del repositorio:

```text
Tests:      185 passed
Assertions: 1240
```

Para ejecutarla:

```bash
php artisan test
```

## Instalación local

### Requisitos

- PHP 8.2 o superior con extensiones habituales de Laravel, incluida BCMath.
- Composer.
- Node.js y npm.
- MySQL o MariaDB.
- `mysqldump` y soporte Gzip para utilizar el módulo de respaldos.

### Configuración

```bash
git clone <URL_DEL_REPOSITORIO>
cd QuiQue-Micromarket
composer install
copy .env.example .env
php artisan key:generate
```

En Linux o macOS, sustituye `copy` por `cp`. Después, crea la base de datos y configura en `.env` al menos `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`.

```bash
php artisan migrate --seed
php artisan micromarket:create-admin
npm install
npm run build
php artisan serve
```

El comando de administrador inicial solicita nombre, correo y contraseña de forma interactiva y rechaza la creación de un segundo administrador. El seeder crea los roles **Administrador** y **Cajero**, además de la sucursal principal; no incluye credenciales predeterminadas.

Para desarrollo con recarga de assets puede utilizarse `npm run dev`. El repositorio también incluye un ejemplo de VirtualHost para Apache en `deploy/apache`.

### Respaldos automáticos

El respaldo local utiliza las variables `BACKUP_MYSQLDUMP_PATH`, `BACKUP_LOCAL_PATH`, `BACKUP_PROCESS_TIMEOUT` y las opciones `BACKUP_AUTOMATIC_*` del archivo `.env`. Laravel agenda `backup:automatic` cada hora y el servicio decide si corresponde ejecutar un nuevo respaldo según el intervalo configurado.

En producción debe mantenerse activo el scheduler de Laravel. El repositorio incluye una guía específica para el Programador de tareas de Windows en `deploy/backup-scheduler-windows.md`.

## Identidad visual

El repositorio incluye los recursos gráficos propios de QuiQue Micromarket en `public/images`. No contiene capturas de pantalla de la interfaz, por lo que este README no presenta imágenes que no formen parte del proyecto.
