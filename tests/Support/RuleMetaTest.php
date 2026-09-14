<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Support;

use Nayvo\LaravelRaceGuard\Rules\RuleFactory;
use Nayvo\LaravelRaceGuard\Rules\RuleMeta;
use Nayvo\LaravelRaceGuard\Support\Category;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuleMetaTest extends TestCase
{
    #[Test]
    public function every_registered_rule_has_a_code_and_a_valid_category(): void
    {
        foreach (RuleFactory::make() as $rule) {
            $code = RuleMeta::code($rule->id());
            $category = RuleMeta::category($rule->id());

            $this->assertMatchesRegularExpression('/^RG\d{3}$/', $code, "Rule {$rule->id()} has no RG code");
            $this->assertTrue(Category::isValid($category), "Rule {$rule->id()} has an invalid category");
        }
    }

    #[Test]
    public function rule_codes_are_unique(): void
    {
        $codes = [];
        foreach (RuleFactory::make() as $rule) {
            $codes[] = RuleMeta::code($rule->id());
        }

        $this->assertSame(count($codes), count(array_unique($codes)), 'Duplicate RG codes detected');
    }

    #[Test]
    public function the_flagship_codes_map_to_the_expected_rules(): void
    {
        $this->assertSame('RG001', RuleMeta::code('read_modify_write'));
        $this->assertSame('RG004', RuleMeta::code('unsafe_balance_update'));
        $this->assertSame('RG007', RuleMeta::code('transaction_without_lock'));
        $this->assertSame('RG009', RuleMeta::code('overlapping_job'));
        $this->assertSame(Category::PAYMENTS, RuleMeta::category('guard_then_save'));
    }
}
