# RajaOngkir Shipping Integration for Bagisto

High-reliability RajaOngkir API client and Bagisto shipping method package with full caching, dedupe, retry, cooldown protection, and deterministic rate optimization.

## Overview

This package provides a fully production-grade RajaOngkir shipping integration for Bagisto. 
It includes: 
- Optimized RajaOngkir API client 
- Automatic cost caching (15m) 
- Destination caching (24h) 
- Lifecycle request dedupe - Retry + exponential backoff
- Upstream cooldown protection
- Normalized rate sorting (cheapest-first or ETA-first logic)
- Bagisto-compatible shipping method service
- Comprehensive logging with trace IDs

## Features

### 1. Reliable Client

- HTTP retry with incremental delay
- 429/5xx cooldown enforcement
- Fail-soft mode during cooldown
- Structured logging (request, success, failure, exception)
- Normalized request validation
- Deterministic caching
- Lifecycle dedupe to prevent duplicate processing

### 2. Shipping Rate Optimizer

- Remove duplicate courier services by ETA bracket
- Select lowest price per ETA per courier
- Global sort (cheapest → ETA → deterministic tie-breaker)

### 3. Bagisto Integration

- Drop-in service provider
- Configurable API key
- Shipping method class with pre-validation + exception-safe rate fetching
- Production-safe calculate() wrapper
- Full logging


# Requirements

- PHP 8.1+
- Laravel 10+
- Bagisto 2.x

# Installation

    composer require akaradistira/rajaongkir-shipping


## Usage

After installing and configuring the RajaOngkir shipping package, follow these steps to enable and use it in Bagisto.

### 1. Enable Shipping Method in Admin Panel

1. Log in to your Bagisto admin panel.
2. Navigate to **Configure → Sales → Shipping Methods**.
3. Locate **RajaOngkir Shipping** in the list.
4. Set **Status** to `Active`.
5. Configure additional options:
   - **Origin City** – the shipping origin for your store.
   - **Enabled Couriers** – select couriers supported by RajaOngkir.
   - **Rate Sorting** – choose `Cheapest First` or `Fastest ETA First`.
6. Save changes.

### 2. Checkout Usage

Once enabled, RajaOngkir shipping rates will automatically appear on the checkout page for eligible destinations. Calculation is automatic and based on:
- Cart Contents: weights and quantities of items in the cart.
- Origin & Destination: origin city from admin config, destination from customer shipping address.
- RajaOngkir API: fetches live rates.
- Optimization: deduplication, caching, and global rate sorting.

Rates are:
- Cached for 15 minutes (costs) and 24 hours (destination info).
- Deduplicated by courier and ETA.
- Sorted according to the selected rate optimization logic.

### 3. Logs & Troubleshooting

All API requests are logged with structured trace IDs:


# Additionnal Information

## Rate Optimization Logic

### Step 1: Normalize ETA window

### Step 2: Deduplicate per Courier

### Step 3: Global Sort

- Cheapest price
- Fastest ETA
- Deterministic fallback by courier code

## Logging

All requests include structured logs.

## Caching & Dedupe

- Destination cache: 24h
- Cost cache: 15m
- API cooldown: 60s

# License
MIT License

Copyright (c) 2025 N.Pratama

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the “Software”), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED “AS IS”, WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
