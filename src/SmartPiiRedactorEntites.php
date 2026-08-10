<?php

namespace TheJenos\SmartPiiRedactor;

enum SmartPiiRedactorEntites: string
{
    case NAME = 'NAME';
    case ORGANIZATION = 'ORGANIZATION';
    case LOCATION = 'LOCATION';

    case EMAIL = 'EMAIL';
    case URL = 'URL';
    case IPV4_ADDRESS = 'IPV4_ADDRESS';
    case IPV6_ADDRESS = 'IPV6_ADDRESS';
    case SSN = 'SSN';
    case CREDIT_CARD = 'CREDIT_CARD';
    case PHONE = 'PHONE';
    case IBAN = 'IBAN';
    case API_KEY = 'API_KEY';
    case BEARER_TOKEN = 'BEARER_TOKEN';

    public static function modelEntities(): array
    {
        return self::toValue([
            self::NAME,
            self::ORGANIZATION,
            self::LOCATION,
        ]);
    }

    public static function regexEntities(): array
    {
        return self::toValue([
            self::EMAIL,
            self::URL,
            self::IPV4_ADDRESS,
            self::IPV6_ADDRESS,
            self::SSN,
            self::CREDIT_CARD,
            self::PHONE,
            self::IBAN,
            self::API_KEY,
            self::BEARER_TOKEN,
        ]);
    }

    public static function all(): array
    {
        return array_merge(self::modelEntities(), self::regexEntities());
    }

    public static function toValue($array): array | string
    {
        if (is_array($array)) {
            return array_map(function ($item) {
                if ($item instanceof self) {
                    return $item->value;
                }

                return $item;
            }, $array);
        }

        if ($array instanceof self) {
            return $array->value;
        }

        throw new \Exception('Invalid array of SmartPiiRedactorEntites');
    }
}