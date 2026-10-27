<?php

namespace Wpl;

/**
 * Anonymous visitors are recognised by a random cookie, so they can find
 * their demos again without an account. Only a hash of it is stored.
 */
class Visitor
{
    const COOKIE = 'wpl_vid';

    public static function id(): string
    {
        $id = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^[a-z0-9]{32}$/', $id)) {
            $id = random_str(32);
            Http::cookie(self::COOKIE, $id, 365 * 86400);
        }
        return $id;
    }

    public static function ownerHash(): string
    {
        return hash_hmac('sha256', self::id(), Auth::secret());
    }
}
