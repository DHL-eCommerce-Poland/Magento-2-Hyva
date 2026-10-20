# DHL eCommerce Poland Hyvä Checkout Compatibility Module

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP: >=8.1](https://img.shields.io/badge/PHP-%3E%3D8.1-blue.svg)](composer.json)
[![Magento: >=2.4.4](https://img.shields.io/badge/Magento-%3E%3D2.4.4-orange.svg)](composer.json)

Hyvä Checkout compatibility module for **[DHL eCommerce Poland](https://github.com/dhl-poland/module-dhl)** (`DhlPoland_Dhl`).

This module bridges the native DHL Poland shipping capabilities into the high-performance **Hyvä Checkout** environment using **Magewire**, **Alpine.js**, and **Tailwind CSS**.

---

## Features

- **Full DHL Shipping Method Support**: Seamlessly renders all domestic and international DHL shipping methods with their official logos.
- **Interactive DHL Map Modal**: Customers choosing ServicePoint (POP) or Locker (Box) delivery can search and select pickup points using the official DHL map directly inside a modal.
- **Zero Business Logic Duplication**: Reuses the core module's [`MethodCatalog`](../Dhl/Model/MethodCatalog.php) and [`ServicePointValidator`](../Dhl/Model/ServicePoint/ServicePointValidator.php). If services or validation rules evolve in `DhlPoland_Dhl`, this module automatically inherits them.
- **German Packstation & Postnummer**: Real-time entry and validation of German customer numbers (6–10 digits) for Packstation deliveries in Germany.
- **Checkout Step Validation**: Integrates with Hyvä Checkout's Magewire `EvaluationInterface` to ensure customers select a required pickup point before advancing to payment or placing the order.
- **CSP Compliant**: Uses Content Security Policy compliant Alpine.js patterns with no unsafe eval or inline scripts.
- **Order Synchronization**: Utilizes existing quote-to-order fieldset mapping so orders placed on Hyvä Checkout include full pickup point data and enriched descriptions.

---

## Requirements

- **PHP**: `>=8.1`
- **Magento Open Source / Adobe Commerce**: `>=2.4.4`
- **Hyvä Checkout**: `^1.3`
- **DHL Poland Base Module**: `dhl-poland/module-dhl: ^1.0`

---

## Installation

Install the package via Composer:

```bash
composer require dhl-poland/module-hyva-dhl
```

Enable the module in Magento:

```bash
bin/magento module:enable DhlPoland_HyvaDhl
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

---

## Architecture

```
Hyvä Checkout (Storefront)
  ├── Alpine.js Component (Modal + postMessage listener)
  ├── Magewire Component (PointSelector: State + Quote Persistence)
  └── MethodList Interceptor (ShippingMethodListPlugin)
           │
           ▼
DhlPoland_Dhl (Domain Services)
  ├── MethodCatalog (Service & point eligibility)
  ├── ServicePointValidator (Rules & Postnummer validation)
  └── Quote-to-Order Conversion (fieldset.xml & plugins)
```

The compatibility layer remains intentionally thin. It handles UI reactivity and Magewire hydration while delegating all DHL business rules and validations to `DhlPoland_Dhl`.

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
