<?php

namespace Tests\Unit;

use App\Support\Validators;
use PHPUnit\Framework\TestCase;

class ValidatorsTest extends TestCase
{
    public function test_valid_domains_are_accepted(): void
    {
        $this->assertTrue(Validators::isValidDomain('example.com'));
        $this->assertTrue(Validators::isValidDomain('sub.example.com'));
        $this->assertTrue(Validators::isValidDomain('my-site.co.uk'));
    }

    public function test_invalid_domains_are_rejected(): void
    {
        $this->assertFalse(Validators::isValidDomain(''));
        $this->assertFalse(Validators::isValidDomain('-bad.com'));
        $this->assertFalse(Validators::isValidDomain('no spaces.com'));
        $this->assertFalse(Validators::isValidDomain('localhost'));
        $this->assertFalse(Validators::isValidDomain('../etc/passwd'));
    }

    public function test_username_validation(): void
    {
        $this->assertTrue(Validators::isValidUsername('webuser'));
        $this->assertTrue(Validators::isValidUsername('app_1'));
        $this->assertFalse(Validators::isValidUsername('1user'));   // must start with a letter
        $this->assertFalse(Validators::isValidUsername('ab'));      // too short
        $this->assertFalse(Validators::isValidUsername('Root'));    // uppercase not allowed
        $this->assertFalse(Validators::isValidUsername('user;rm'));
    }

    public function test_database_name_validation(): void
    {
        $this->assertTrue(Validators::isValidDatabaseName('my_app'));
        $this->assertFalse(Validators::isValidDatabaseName('my-app'));
        $this->assertFalse(Validators::isValidDatabaseName('drop;table'));
    }

    public function test_port_validation(): void
    {
        $this->assertTrue(Validators::isValidPort(80));
        $this->assertTrue(Validators::isValidPort(65535));
        $this->assertFalse(Validators::isValidPort(0));
        $this->assertFalse(Validators::isValidPort(70000));
    }

    public function test_cron_schedule_validation(): void
    {
        $this->assertTrue(Validators::isValidCronSchedule('0 3 * * *'));
        $this->assertTrue(Validators::isValidCronSchedule('*/5 * * * *'));
        $this->assertFalse(Validators::isValidCronSchedule('0 3 * *'));      // only 4 fields
        $this->assertFalse(Validators::isValidCronSchedule('0 3 * * * rm'));  // 6 fields
    }
}
