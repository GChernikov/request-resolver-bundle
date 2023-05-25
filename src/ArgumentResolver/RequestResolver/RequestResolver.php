<?php

declare(strict_types=1);

namespace GChernikov\RequestResolverBundle\ArgumentResolver\RequestResolver;

use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

/**
 * @template T
 */
final class RequestResolver
{
    /**
     * @param array<class-string<T>, array<string, array<string, string>>> $supportedRequests
     * @example [
     *     "requestClass" => [
     *         "route:name" => [
     *             "parameter_name" => "path",
     *         ],
     *     ],
     * ]
     */
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly array $supportedRequests,
        private readonly NameConverterInterface $nameConverter,
    ) {
    }

    public function supports(string $className): bool
    {
        return isset($this->supportedRequests[$className]);
    }

    /**
     * @throws ReflectionException
     * @throws ValidationFailedException
     *
     * @psalm-param class-string<TRequest> $requestClassName
     *
     * @return TRequest
     */
    public function resolve(Request $request, string $requestClassName): object
    {
        $reflection = new ReflectionClass($requestClassName);
        $requestDto = $reflection->newInstanceWithoutConstructor();
        $properties = $this->getReflectionProperties($reflection);

        if (!$properties) {
            /** It can be empty request class (just command) */
            return $requestDto;
        }

        $violations = new ConstraintViolationList();
        $parameters = $this->getParameters($request, $reflection->getName(), ...array_keys($properties));

        foreach ($properties as $name => $reflectionProperty) {
            $value = $parameters[$name];

            try {
                $reflectionProperty->setValue($requestDto, $value);
                $valueToValidate = $reflectionProperty->getValue($requestDto);
            } catch (Throwable) {
                $valueToValidate = $value;
            }

            $violations->addAll(
                otherList: $this->validate(
                    requestDto: $requestDto,
                    property: $name,
                    value: $valueToValidate,
                ),
            );
        }

        $this->removeDuplicateMessages($violations);

        if ($violations->count()) {
            throw new ValidationFailedException(
                value: $requestDto,
                violations: $violations,
            );
        }

        return $requestDto;
    }

    private function validate(object $requestDto, string $property, mixed $value): ConstraintViolationList
    {
        $validator = $this->validator->startContext()->validatePropertyValue(
            objectOrClass: $requestDto,
            propertyName: $property,
            value: $value,
        );

        return $validator->getViolations();
    }

    private function removeDuplicateMessages(ConstraintViolationList $violations): void
    {
        $map = [];

        foreach ($violations as $violationOffset => $violation) {
            if (!isset($map[$violation->getPropertyPath()][$violation->getMessage()])) {
                $map[$violation->getPropertyPath()][$violation->getMessage()] = 1;
            } else {
                $violations->remove($violationOffset);
            }
        }
    }

    /**
     * @return array<ReflectionProperty>
     * @throws ReflectionException
     */
    private function getReflectionProperties(?ReflectionClass $reflection = null): array
    {
        if ($reflection === null) {
            return [];
        }

        $properties = $this->getReflectionProperties(
            reflection: $reflection->getParentClass() ?: null,
        );

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $properties[$property->getName()] ??= $property;
        }

        return $properties;
    }

    private function getParameters(Request $request, string $type, string ...$properties): iterable
    {
        $mapping = $this->getMapping(
            $request,
            $type,
        );

        try {
            $parametersBag = $request->toArray();
        } catch (Throwable) {
            $parametersBag = array_merge(
                $request->request->all(),
                $request->query->all()
            );
        }

        $parameters = [];

        foreach ($properties as $propertyName) {
            $snakePropertyName = $this->nameConverter->normalize($propertyName);

            $parameters[$propertyName] = match ($mapping[$propertyName] ?? null) {
                'path' => $request->attributes->get($propertyName) ?? $request->attributes->get($snakePropertyName),
                'query' => $request->query->get($propertyName) ?? $request->query->get($snakePropertyName),
                'header' => $request->headers->get($propertyName) ?? $request->headers->get($snakePropertyName),
                default => $parametersBag[$propertyName] ?? $parametersBag[$snakePropertyName] ?? null,
            };
        }

        return $parameters;
    }

    /**
     * @return array<string, string>|false
     */
    private function getMapping(Request $request, string $type): array|false
    {
        return $this->supportedRequests[$type][$request->attributes->get('_route')] ?? false;
    }
}
