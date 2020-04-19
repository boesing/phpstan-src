<?php declare(strict_types = 1);

namespace PHPStan\Reflection;

use PHPStan\PhpDoc\ResolvedPhpDocBlock;
use PHPStan\PhpDoc\Tag\ExtendsTag;
use PHPStan\PhpDoc\Tag\ImplementsTag;
use PHPStan\PhpDoc\Tag\TemplateTag;
use PHPStan\Reflection\Php\PhpClassReflectionExtension;
use PHPStan\Reflection\Php\PhpPropertyReflection;
use PHPStan\Type\ErrorType;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Generic\TemplateTypeFactory;
use PHPStan\Type\Generic\TemplateTypeHelper;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Generic\TemplateTypeScope;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

class ClassReflection implements ReflectionWithFilename
{

	/** @var \PHPStan\Reflection\ReflectionProvider */
	private $reflectionProvider;

	/** @var \PHPStan\Type\FileTypeMapper */
	private $fileTypeMapper;

	/** @var \PHPStan\Reflection\PropertiesClassReflectionExtension[] */
	private $propertiesClassReflectionExtensions;

	/** @var \PHPStan\Reflection\MethodsClassReflectionExtension[] */
	private $methodsClassReflectionExtensions;

	/** @var string */
	private $displayName;

	/** @var \ReflectionClass */
	private $reflection;

	/** @var string|null */
	private $anonymousFilename;

	/** @var \PHPStan\Reflection\MethodReflection[] */
	private $methods = [];

	/** @var \PHPStan\Reflection\PropertyReflection[] */
	private $properties = [];

	/** @var \PHPStan\Reflection\ConstantReflection[] */
	private $constants;

	/** @var int[]|null */
	private $classHierarchyDistances;

	/** @var string|null */
	private $deprecatedDescription;

	/** @var bool|null */
	private $isDeprecated;

	/** @var bool|null */
	private $isGeneric;

	/** @var bool|null */
	private $isInternal;

	/** @var bool|null */
	private $isFinal;

	/** @var ?TemplateTypeMap */
	private $templateTypeMap;

	/** @var ?TemplateTypeMap */
	private $resolvedTemplateTypeMap;

	/** @var ResolvedPhpDocBlock|null */
	private $stubPhpDocBlock;

	/** @var array<string,ClassReflection>|null */
	private $ancestors;

	/** @var string|null */
	private $cacheKey;

	/** @var array<string, bool> */
	private $subclasses = [];

	/** @var string|false|null */
	private $filename;

	/**
	 * @param \PHPStan\Reflection\ReflectionProvider $reflectionProvider
	 * @param \PHPStan\Type\FileTypeMapper $fileTypeMapper
	 * @param \PHPStan\Reflection\PropertiesClassReflectionExtension[] $propertiesClassReflectionExtensions
	 * @param \PHPStan\Reflection\MethodsClassReflectionExtension[] $methodsClassReflectionExtensions
	 * @param string $displayName
	 * @param \ReflectionClass $reflection
	 * @param string|null $anonymousFilename
	 * @param ResolvedPhpDocBlock|null $stubPhpDocBlock
	 */
	public function __construct(
		ReflectionProvider $reflectionProvider,
		FileTypeMapper $fileTypeMapper,
		array $propertiesClassReflectionExtensions,
		array $methodsClassReflectionExtensions,
		string $displayName,
		\ReflectionClass $reflection,
		?string $anonymousFilename,
		?TemplateTypeMap $resolvedTemplateTypeMap,
		?ResolvedPhpDocBlock $stubPhpDocBlock
	)
	{
		$this->reflectionProvider = $reflectionProvider;
		$this->fileTypeMapper = $fileTypeMapper;
		$this->propertiesClassReflectionExtensions = $propertiesClassReflectionExtensions;
		$this->methodsClassReflectionExtensions = $methodsClassReflectionExtensions;
		$this->displayName = $displayName;
		$this->reflection = $reflection;
		$this->anonymousFilename = $anonymousFilename;
		$this->resolvedTemplateTypeMap = $resolvedTemplateTypeMap;
		$this->stubPhpDocBlock = $stubPhpDocBlock;
	}

	public function getNativeReflection(): \ReflectionClass
	{
		return $this->reflection;
	}

	/**
	 * @return string|false
	 */
	public function getFileName()
	{
		if (isset($this->filename)) {
			return $this->filename;
		}

		if ($this->anonymousFilename !== null) {
			return $this->filename = $this->anonymousFilename;
		}
		$fileName = $this->reflection->getFileName();
		if ($fileName === false) {
			return $this->filename = false;
		}

		if (!file_exists($fileName)) {
			return $this->filename = false;
		}

		return $this->filename = $fileName;
	}

	public function getFileNameWithPhpDocs(): ?string
	{
		if ($this->stubPhpDocBlock !== null) {
			return $this->stubPhpDocBlock->getFilename();
		}

		$filename = $this->getFileName();
		if ($filename === false) {
			return null;
		}

		return $filename;
	}

	/**
	 * @return false|\PHPStan\Reflection\ClassReflection
	 */
	public function getParentClass()
	{
		$parentClass = $this->reflection->getParentClass();

		if ($parentClass === false) {
			return false;
		}

		$extendsTag = $this->getFirstExtendsTag();

		if ($extendsTag !== null && $this->isValidAncestorType($extendsTag->getType(), [$parentClass->getName()])) {
			$extendedType = $extendsTag->getType();

			if ($this->isGeneric()) {
				$extendedType = TemplateTypeHelper::resolveTemplateTypes(
					$extendedType,
					$this->getActiveTemplateTypeMap()
				);
			}

			if (!$extendedType instanceof GenericObjectType) {
				return $this->reflectionProvider->getClass($parentClass->getName());
			}

			return $extendedType->getClassReflection() ?? $this->reflectionProvider->getClass($parentClass->getName());
		}

		$parentReflection = $this->reflectionProvider->getClass($parentClass->getName());
		if ($parentReflection->isGeneric()) {
			return $parentReflection->withTypes(
				array_values($parentReflection->getTemplateTypeMap()->resolveToBounds()->getTypes())
			);
		}

		return $parentReflection;
	}

	public function getName(): string
	{
		return $this->reflection->getName();
	}

	public function getDisplayName(bool $withTemplateTypes = true): string
	{
		$name = $this->displayName;

		if (
			$withTemplateTypes === false
			|| $this->resolvedTemplateTypeMap === null
			|| count($this->resolvedTemplateTypeMap->getTypes()) === 0
		) {
			return $name;
		}

		return $name . '<' . implode(',', array_map(static function (Type $type): string {
			return $type->describe(VerbosityLevel::typeOnly());
		}, $this->resolvedTemplateTypeMap->getTypes())) . '>';
	}

	public function getCacheKey(): string
	{
		$cacheKey = $this->cacheKey;
		if ($cacheKey !== null) {
			return $this->cacheKey;
		}

		$cacheKey = $this->displayName;

		if ($this->resolvedTemplateTypeMap !== null) {
			$cacheKey .= '<' . implode(',', array_map(static function (Type $type): string {
				return $type->describe(VerbosityLevel::cache());
			}, $this->resolvedTemplateTypeMap->getTypes())) . '>';
		}

		$this->cacheKey = $cacheKey;

		return $cacheKey;
	}

	/**
	 * @return int[]
	 */
	public function getClassHierarchyDistances(): array
	{
		if ($this->classHierarchyDistances === null) {
			$distance = 0;
			$distances = [
				$this->getName() => $distance,
			];
			$currentClassReflection = $this->getNativeReflection();
			foreach ($this->collectTraits($this->getNativeReflection()) as $trait) {
				$distance++;
				if (array_key_exists($trait->getName(), $distances)) {
					continue;
				}

				$distances[$trait->getName()] = $distance;
			}

			while ($currentClassReflection->getParentClass() !== false) {
				$distance++;
				$parentClassName = $currentClassReflection->getParentClass()->getName();
				if (!array_key_exists($parentClassName, $distances)) {
					$distances[$parentClassName] = $distance;
				}
				$currentClassReflection = $currentClassReflection->getParentClass();
				foreach ($this->collectTraits($currentClassReflection) as $trait) {
					$distance++;
					if (array_key_exists($trait->getName(), $distances)) {
						continue;
					}

					$distances[$trait->getName()] = $distance;
				}
			}
			foreach ($this->getNativeReflection()->getInterfaces() as $interface) {
				$distance++;
				if (array_key_exists($interface->getName(), $distances)) {
					continue;
				}

				$distances[$interface->getName()] = $distance;
			}

			$this->classHierarchyDistances = $distances;
		}

		return $this->classHierarchyDistances;
	}

	/**
	 * @param \ReflectionClass $class
	 * @return \ReflectionClass[]
	 */
	private function collectTraits(\ReflectionClass $class): array
	{
		$traits = [];
		$traitsLeftToAnalyze = $class->getTraits();

		while (count($traitsLeftToAnalyze) !== 0) {
			$trait = reset($traitsLeftToAnalyze);
			$traits[] = $trait;

			foreach ($trait->getTraits() as $subTrait) {
				if (in_array($subTrait, $traits, true)) {
					continue;
				}

				$traitsLeftToAnalyze[] = $subTrait;
			}

			array_shift($traitsLeftToAnalyze);
		}

		return $traits;
	}

	public function hasProperty(string $propertyName): bool
	{
		foreach ($this->propertiesClassReflectionExtensions as $extension) {
			if ($extension->hasProperty($this, $propertyName)) {
				return true;
			}
		}

		return false;
	}

	public function hasMethod(string $methodName): bool
	{
		foreach ($this->methodsClassReflectionExtensions as $extension) {
			if ($extension->hasMethod($this, $methodName)) {
				return true;
			}
		}

		return false;
	}

	public function getMethod(string $methodName, ClassMemberAccessAnswerer $scope): MethodReflection
	{
		$key = $methodName;
		if ($scope->isInClass()) {
			$key = sprintf('%s-%s', $key, $scope->getClassReflection()->getCacheKey());
		}
		if (!isset($this->methods[$key])) {
			foreach ($this->methodsClassReflectionExtensions as $extension) {
				if (!$extension->hasMethod($this, $methodName)) {
					continue;
				}

				$method = $extension->getMethod($this, $methodName);
				if ($scope->canCallMethod($method)) {
					return $this->methods[$key] = $method;
				}
				$this->methods[$key] = $method;
			}
		}

		if (!isset($this->methods[$key])) {
			$filename = $this->getFileName();
			throw new \PHPStan\Reflection\MissingMethodFromReflectionException($this->getName(), $methodName, $filename !== false ? $filename : null);
		}

		return $this->methods[$key];
	}

	public function hasNativeMethod(string $methodName): bool
	{
		return $this->getPhpExtension()->hasNativeMethod($this, $methodName);
	}

	public function getNativeMethod(string $methodName): MethodReflection
	{
		if (!$this->hasNativeMethod($methodName)) {
			$filename = $this->getFileName();
			throw new \PHPStan\Reflection\MissingMethodFromReflectionException($this->getName(), $methodName, $filename !== false ? $filename : null);
		}
		return $this->getPhpExtension()->getNativeMethod($this, $methodName);
	}

	public function hasConstructor(): bool
	{
		return $this->reflection->getConstructor() !== null;
	}

	public function getConstructor(): MethodReflection
	{
		$constructor = $this->reflection->getConstructor();
		if ($constructor === null) {
			throw new \PHPStan\ShouldNotHappenException();
		}
		return $this->getNativeMethod($constructor->getName());
	}

	private function getPhpExtension(): PhpClassReflectionExtension
	{
		$extension = $this->methodsClassReflectionExtensions[0];
		if (!$extension instanceof PhpClassReflectionExtension) {
			throw new \PHPStan\ShouldNotHappenException();
		}

		return $extension;
	}

	public function getProperty(string $propertyName, ClassMemberAccessAnswerer $scope): PropertyReflection
	{
		$key = $propertyName;
		if ($scope->isInClass()) {
			$key = sprintf('%s-%s', $key, $scope->getClassReflection()->getCacheKey());
		}
		if (!isset($this->properties[$key])) {
			foreach ($this->propertiesClassReflectionExtensions as $extension) {
				if (!$extension->hasProperty($this, $propertyName)) {
					continue;
				}

				$property = $extension->getProperty($this, $propertyName);
				if ($scope->canAccessProperty($property)) {
					return $this->properties[$key] = $property;
				}
				$this->properties[$key] = $property;
			}
		}

		if (!isset($this->properties[$key])) {
			$filename = $this->getFileName();
			throw new \PHPStan\Reflection\MissingPropertyFromReflectionException($this->getName(), $propertyName, $filename !== false ? $filename : null);
		}

		return $this->properties[$key];
	}

	public function hasNativeProperty(string $propertyName): bool
	{
		return $this->getPhpExtension()->hasProperty($this, $propertyName);
	}

	public function getNativeProperty(string $propertyName): PhpPropertyReflection
	{
		if (!$this->hasNativeProperty($propertyName)) {
			$filename = $this->getFileName();
			throw new \PHPStan\Reflection\MissingPropertyFromReflectionException($this->getName(), $propertyName, $filename !== false ? $filename : null);
		}

		return $this->getPhpExtension()->getNativeProperty($this, $propertyName);
	}

	public function isAbstract(): bool
	{
		return $this->reflection->isAbstract();
	}

	public function isInterface(): bool
	{
		return $this->reflection->isInterface();
	}

	public function isTrait(): bool
	{
		return $this->reflection->isTrait();
	}

	public function isAnonymous(): bool
	{
		return $this->anonymousFilename !== null;
	}

	public function isSubclassOf(string $className): bool
	{
		if (isset($this->subclasses[$className])) {
			return $this->subclasses[$className];
		}

		if (!$this->reflectionProvider->hasClass($className)) {
			return $this->subclasses[$className] = false;
		}

		return $this->subclasses[$className] = $this->reflection->isSubclassOf($className);
	}

	/**
	 * @return \PHPStan\Reflection\ClassReflection[]
	 */
	public function getParents(): array
	{
		$parents = [];
		$parent = $this->getParentClass();
		while ($parent !== false) {
			$parents[] = $parent;
			$parent = $parent->getParentClass();
		}

		return $parents;
	}

	/**
	 * @return \PHPStan\Reflection\ClassReflection[]
	 */
	public function getInterfaces(): array
	{
		$interfaces = [];

		$parent = $this->getParentClass();
		if ($parent !== false) {
			foreach ($parent->getInterfaces() as $interface) {
				$interfaces[$interface->getName()] = $interface;
			}
		}

		if ($this->reflection->isInterface()) {
			$implementsTags = $this->getExtendsTags();
		} else {
			$implementsTags = $this->getImplementsTags();
		}

		$interfaceNames = $this->reflection->getInterfaceNames();
		$genericInterfaces = [];

		foreach ($implementsTags as $implementsTag) {
			$implementedType = $implementsTag->getType();

			if (!$this->isValidAncestorType($implementedType, $interfaceNames)) {
				continue;
			}

			if ($this->isGeneric()) {
				$implementedType = TemplateTypeHelper::resolveTemplateTypes(
					$implementedType,
					$this->getActiveTemplateTypeMap()
				);
			}

			if (!$implementedType instanceof GenericObjectType) {
				continue;
			}

			$reflectionIface = $implementedType->getClassReflection();
			if ($reflectionIface === null) {
				continue;
			}

			$genericInterfaces[] = $reflectionIface;
		}

		foreach ($genericInterfaces as $genericInterface) {
			$interfaces = array_merge($interfaces, $genericInterface->getInterfaces());
		}

		foreach ($genericInterfaces as $genericInterface) {
			$interfaces[$genericInterface->getName()] = $genericInterface;
		}

		foreach ($interfaceNames as $interfaceName) {
			if (isset($interfaces[$interfaceName])) {
				continue;
			}

			$interfaceReflection = $this->reflectionProvider->getClass($interfaceName);
			if (!$interfaceReflection->isGeneric()) {
				$interfaces[$interfaceName] = $interfaceReflection;
				continue;
			}

			$interfaces[$interfaceName] = $interfaceReflection->withTypes(
				array_values($interfaceReflection->getTemplateTypeMap()->resolveToBounds()->getTypes())
			);
		}

		return $interfaces;
	}

	/**
	 * @return \PHPStan\Reflection\ClassReflection[]
	 */
	public function getTraits(): array
	{
		return array_map(function (\ReflectionClass $trait): ClassReflection {
			return $this->reflectionProvider->getClass($trait->getName());
		}, $this->getNativeReflection()->getTraits());
	}

	/**
	 * @return string[]
	 */
	public function getParentClassesNames(): array
	{
		$parentNames = [];
		$currentClassReflection = $this;
		while ($currentClassReflection->getParentClass() !== false) {
			$parentNames[] = $currentClassReflection->getParentClass()->getName();
			$currentClassReflection = $currentClassReflection->getParentClass();
		}

		return $parentNames;
	}

	public function hasConstant(string $name): bool
	{
		return $this->getNativeReflection()->hasConstant($name);
	}

	public function getConstant(string $name): ConstantReflection
	{
		if (!isset($this->constants[$name])) {
			$reflectionConstant = $this->getNativeReflection()->getReflectionConstant($name);
			if ($reflectionConstant === false) {
				$fileName = $this->getFileName();
				throw new \PHPStan\Reflection\MissingConstantFromReflectionException($this->getName(), $name, $fileName !== false ? $fileName : null);
			}

			$deprecatedDescription = null;
			$isDeprecated = false;
			$isInternal = false;
			if ($reflectionConstant->getDocComment() !== false && $this->getFileName() !== false) {
				$docComment = $reflectionConstant->getDocComment();
				$fileName = $this->getFileName();
				$className = $reflectionConstant->getDeclaringClass()->getName();
				$resolvedPhpDoc = $this->fileTypeMapper->getResolvedPhpDoc($fileName, $className, null, null, $docComment);

				$deprecatedDescription = $resolvedPhpDoc->getDeprecatedTag() !== null ? $resolvedPhpDoc->getDeprecatedTag()->getMessage() : null;
				$isDeprecated = $resolvedPhpDoc->isDeprecated();
				$isInternal = $resolvedPhpDoc->isInternal();
			}

			$this->constants[$name] = new ClassConstantReflection(
				$this->reflectionProvider->getClass($reflectionConstant->getDeclaringClass()->getName()),
				$reflectionConstant,
				$deprecatedDescription,
				$isDeprecated,
				$isInternal
			);
		}
		return $this->constants[$name];
	}

	public function hasTraitUse(string $traitName): bool
	{
		return in_array($traitName, $this->getTraitNames(), true);
	}

	/**
	 * @return string[]
	 */
	private function getTraitNames(): array
	{
		$class = $this->reflection;
		$traitNames = $class->getTraitNames();
		while ($class->getParentClass() !== false) {
			$traitNames = array_values(array_unique(array_merge($traitNames, $class->getParentClass()->getTraitNames())));
			$class = $class->getParentClass();
		}

		return $traitNames;
	}

	public function getDeprecatedDescription(): ?string
	{
		if ($this->deprecatedDescription === null && $this->isDeprecated()) {
			$resolvedPhpDoc = $this->getResolvedPhpDoc();
			if ($resolvedPhpDoc !== null && $resolvedPhpDoc->getDeprecatedTag() !== null) {
				$this->deprecatedDescription = $resolvedPhpDoc->getDeprecatedTag()->getMessage();
			}
		}

		return $this->deprecatedDescription;
	}

	public function isDeprecated(): bool
	{
		if ($this->isDeprecated === null) {
			$resolvedPhpDoc = $this->getResolvedPhpDoc();
			$this->isDeprecated = $resolvedPhpDoc !== null && $resolvedPhpDoc->isDeprecated();
		}

		return $this->isDeprecated;
	}

	public function isInternal(): bool
	{
		if ($this->isInternal === null) {
			$resolvedPhpDoc = $this->getResolvedPhpDoc();
			$this->isInternal = $resolvedPhpDoc !== null && $resolvedPhpDoc->isInternal();
		}

		return $this->isInternal;
	}

	public function isFinal(): bool
	{
		if ($this->isFinal === null) {
			$resolvedPhpDoc = $this->getResolvedPhpDoc();
			$this->isFinal = $this->reflection->isFinal()
				|| ($resolvedPhpDoc !== null && $resolvedPhpDoc->isFinal());
		}

		return $this->isFinal;
	}

	public function getTemplateTypeMap(): TemplateTypeMap
	{
		if ($this->templateTypeMap !== null) {
			return $this->templateTypeMap;
		}

		$resolvedPhpDoc = $this->getResolvedPhpDoc();
		if ($resolvedPhpDoc === null) {
			$this->templateTypeMap = TemplateTypeMap::createEmpty();
			return $this->templateTypeMap;
		}

		$templateTypeMap = new TemplateTypeMap(array_map(function (TemplateTag $tag): Type {
			return TemplateTypeFactory::fromTemplateTag(
				TemplateTypeScope::createWithClass($this->getName()),
				$tag
			);
		}, $this->getTemplateTags()));

		$this->templateTypeMap = $templateTypeMap;

		return $templateTypeMap;
	}

	public function getActiveTemplateTypeMap(): TemplateTypeMap
	{
		return $this->resolvedTemplateTypeMap ?? $this->getTemplateTypeMap();
	}

	public function isGeneric(): bool
	{
		if ($this->isGeneric === null) {
			$this->isGeneric = count($this->getTemplateTags()) > 0;
		}

		return $this->isGeneric;
	}

	/**
	 * @param array<int, Type> $types
	 * @return \PHPStan\Type\Generic\TemplateTypeMap
	 */
	public function typeMapFromList(array $types): TemplateTypeMap
	{
		$resolvedPhpDoc = $this->getResolvedPhpDoc();
		if ($resolvedPhpDoc === null) {
			return TemplateTypeMap::createEmpty();
		}

		$map = [];
		$i = 0;
		foreach ($resolvedPhpDoc->getTemplateTags() as $tag) {
			$map[$tag->getName()] = $types[$i] ?? new ErrorType();
			$i++;
		}

		return new TemplateTypeMap($map);
	}

	/** @return array<int, Type> */
	public function typeMapToList(TemplateTypeMap $typeMap): array
	{
		$resolvedPhpDoc = $this->getResolvedPhpDoc();
		if ($resolvedPhpDoc === null) {
			return [];
		}

		$list = [];
		foreach ($resolvedPhpDoc->getTemplateTags() as $tag) {
			$list[] = $typeMap->getType($tag->getName()) ?? new ErrorType();
		}

		return $list;
	}

	/**
	 * @param array<int, Type> $types
	 */
	public function withTypes(array $types): self
	{
		return new self(
			$this->reflectionProvider,
			$this->fileTypeMapper,
			$this->propertiesClassReflectionExtensions,
			$this->methodsClassReflectionExtensions,
			$this->displayName,
			$this->reflection,
			$this->anonymousFilename,
			$this->typeMapFromList($types),
			$this->stubPhpDocBlock
		);
	}

	private function getResolvedPhpDoc(): ?ResolvedPhpDocBlock
	{
		if ($this->stubPhpDocBlock !== null) {
			return $this->stubPhpDocBlock;
		}

		$fileName = $this->getFileName();
		if ($fileName === false) {
			return null;
		}

		$docComment = $this->reflection->getDocComment();
		if ($docComment === false) {
			return null;
		}

		return $this->fileTypeMapper->getResolvedPhpDoc($fileName, $this->getName(), null, null, $docComment);
	}

	private function getFirstExtendsTag(): ?ExtendsTag
	{
		foreach ($this->getExtendsTags() as $tag) {
			return $tag;
		}

		return null;
	}

	/** @return ExtendsTag[] */
	private function getExtendsTags(): array
	{
		$resolvedPhpDoc = $this->getResolvedPhpDoc();
		if ($resolvedPhpDoc === null) {
			return [];
		}

		return $resolvedPhpDoc->getExtendsTags();
	}

	/** @return ImplementsTag[] */
	private function getImplementsTags(): array
	{
		$resolvedPhpDoc = $this->getResolvedPhpDoc();
		if ($resolvedPhpDoc === null) {
			return [];
		}

		return $resolvedPhpDoc->getImplementsTags();
	}

	/** @return array<string,TemplateTag> */
	private function getTemplateTags(): array
	{
		$resolvedPhpDoc = $this->getResolvedPhpDoc();
		if ($resolvedPhpDoc === null) {
			return [];
		}

		return $resolvedPhpDoc->getTemplateTags();
	}

	/**
	 * @return array<string,ClassReflection>
	 */
	public function getAncestors(): array
	{
		$ancestors = $this->ancestors;

		if ($ancestors === null) {
			$ancestors = [
				$this->getName() => $this,
			];

			$addToAncestors = static function (string $name, ClassReflection $classReflection) use (&$ancestors): void {
				if (array_key_exists($name, $ancestors)) {
					return;
				}

				$ancestors[$name] = $classReflection;
			};

			foreach ($this->getInterfaces() as $interface) {
				$addToAncestors($interface->getName(), $interface);
				foreach ($interface->getAncestors() as $name => $ancestor) {
					$addToAncestors($name, $ancestor);
				}
			}

			foreach ($this->getTraits() as $trait) {
				$addToAncestors($trait->getName(), $trait);
				foreach ($trait->getAncestors() as $name => $ancestor) {
					$addToAncestors($name, $ancestor);
				}
			}

			$parent = $this->getParentClass();
			if ($parent !== false) {
				$addToAncestors($parent->getName(), $parent);
				foreach ($parent->getAncestors() as $name => $ancestor) {
					$addToAncestors($name, $ancestor);
				}
			}

			$this->ancestors = $ancestors;
		}

		return $ancestors;
	}

	public function getAncestorWithClassName(string $className): ?self
	{
		return $this->getAncestors()[$className] ?? null;
	}

	/**
	 * @param string[] $ancestorClasses
	 */
	private function isValidAncestorType(Type $type, array $ancestorClasses): bool
	{
		if (!$type instanceof GenericObjectType) {
			return false;
		}

		$reflection = $type->getClassReflection();
		if ($reflection === null) {
			return false;
		}

		return in_array($reflection->getName(), $ancestorClasses, true);
	}

}
