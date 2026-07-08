<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Demo\App\StringUtilities;

#[CoversClass(StringUtilities::class)]
class StringUtilitiesTest extends TestCase
{
    public function testReverseString()
    {
        $utils = new StringUtilities();
        $result = $utils->reverseString('hello');
        $this->assertEquals('olleh', $result);
    }

    public function testCapitalizeString()
    {
        $utils = new StringUtilities();
        $result = $utils->capitalizeString('hello');
        $this->assertEquals('HELLO', $result);
    }

    public function testConcatenateStrings()
    {
        $utils = new StringUtilities();
        $result = $utils->concatenateStrings('hello', ' world');
        $this->assertEquals('hello world', $result);
    }
}