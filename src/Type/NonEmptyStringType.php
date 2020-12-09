<?php declare(strict_types = 1);

namespace PHPStan\Type;

use PHPStan\TrinaryLogic;
use PHPStan\Type\Constant\ConstantStringType;

class NonEmptyStringType extends StringType
{

	public function describe(VerbosityLevel $level): string
	{
		return 'non-empty-string';
	}

	public function accepts(Type $type, bool $strictTypes): TrinaryLogic
	{
		if ($type instanceof self) {
			return TrinaryLogic::createYes();
		}

		$logic = parent::accepts($type, $strictTypes);

		if ($logic->no()) {
			return $logic;
		}

		if ($type instanceof ConstantStringType) {
			return TrinaryLogic::createFromBoolean(!empty($type->getValue()));
		}

		return TrinaryLogic::createNo();
	}
}
