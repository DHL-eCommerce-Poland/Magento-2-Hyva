<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Model;

interface ServicePointAttributes
{
    public const FIELD_SERVICE_POINT_ID = 'dhl_service_point_id';
    public const FIELD_SERVICE_POINT_NAME = 'dhl_service_point_name';
    public const FIELD_SERVICE_POINT_STREET = 'dhl_service_point_street';
    public const FIELD_SERVICE_POINT_POSTCODE = 'dhl_service_point_postcode';
    public const FIELD_SERVICE_POINT_CITY = 'dhl_service_point_city';
    public const FIELD_IS_PACKSTATION = 'dhl_is_packstation';
    public const FIELD_IS_POSTFILIALE = 'dhl_is_postfiliale';
    public const FIELD_POSTNUMMER = 'dhl_postnummer';

    public const ALL_FIELDS = [
        self::FIELD_SERVICE_POINT_ID,
        self::FIELD_SERVICE_POINT_NAME,
        self::FIELD_SERVICE_POINT_STREET,
        self::FIELD_SERVICE_POINT_POSTCODE,
        self::FIELD_SERVICE_POINT_CITY,
        self::FIELD_IS_PACKSTATION,
        self::FIELD_IS_POSTFILIALE,
        self::FIELD_POSTNUMMER,
    ];

    public const EVENT_SHIPPING_METHOD_ERROR = 'shipping:method:error';
    public const EVENT_SHIPPING_METHOD_SUCCESS = 'shipping:method:success';

    public const DEFAULT_COUNTRY = 'PL';
    public const GERMANY_COUNTRY_CODE = 'DE';
}
