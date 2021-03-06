<?php

declare(strict_types=1);

namespace Antidot\Container;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

use function class_exists;
use function sprintf;

class InstanceResolver
{
    private ContainerConfig $config;
    private InstanceCollection $instances;
    private ParamCollection $parameters;
    private ContainerInterface $container;

    public function __construct(ContainerConfig $config, InstanceCollection $instances, ContainerInterface $container)
    {
        $this->config = $config;
        $this->instances = $instances;
        $this->parameters = new ParamCollection($config->get('parameters'));
        $this->container = $container;
    }

    public function setInstanceOf(string $id): void
    {
        if ($this->config->has($id)) {
            $this->setConfiguredInstance($id);
            return;
        }

        if (class_exists($id)) {
            $this->instances->set($id, $this->getAnInstanceOf($id, $id));
        }
    }

    private function getAnInstanceOf(string $id, string $className): object
    {
        $this->parameters->set($id, $this->parameters->has($id) ? $this->parameters->get($id) : []);
        if (false === class_exists($className)) {
            throw new InvalidArgumentException(sprintf('Class "%s" not found for service id "%s".', $className, $id));
        }
        $instance = new ReflectionClass($className);
        if (false === $instance->hasMethod('__construct')) {
            return $instance->newInstance();
        }

        $parameters = (new ReflectionMethod($className, '__construct'))->getParameters();

        $this->parameters->set($id, array_map(function (ReflectionParameter $parameter) use ($id) {
            return $this->makeParameter($id, $parameter);
        }, $parameters));

        return $instance->newInstanceArgs($this->parameters->get($id));
    }

    /**
     * @return mixed|object
     * @throws \ReflectionException
     */
    private function makeParameter(string $id, ReflectionParameter $parameter)
    {
        /** @var null|ReflectionNamedType $paramType */
        $paramType = $parameter->getType();
        $type = null === $paramType ? '' : $paramType->getName();
        if (array_key_exists($parameter->getName(), $this->parameters->get($id))) {
            return $this->getExistingParameter($id, $parameter, $type);
        }

        if ($this->container->has($type)) {
            return $this->container->get($type);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ('' === $type) {
            throw AutowiringException::withNoType($id, $parameter->getName());
        }

        return $this->getAnInstanceOf($type, $type);
    }

    /**
     * @param string $type
     * @return mixed
     */
    private function getExistingParameter(string $id, ReflectionParameter $parameter, string $type)
    {
        if (is_array($this->parameters->get($id)[$parameter->getName()])
            || $this->parameters->get($id)[$parameter->getName()] instanceof $type) {
            return $this->parameters->get($id)[$parameter->getName()];
        }

        if ($this->container->has($this->parameters->get($id)[$parameter->getName()])) {
            return $this->container->get($this->parameters->get($id)[$parameter->getName()]);
        }

        return $this->parameters->get($id)[$parameter->getName()];
    }

    private function setConfiguredInstance(string $id): void
    {
        if (is_callable($this->config->get($id))) {
            $callable = $this->config->get($id);
            $this->instances->set($id, $callable($this->container));
            return;
        }

        if (is_string($this->config->get($id)) && class_exists($this->config->get($id))) {
            $this->instances->set($id, $this->getAnInstanceOf($id, $this->config->get($id)));
            return;
        }

        if (is_string($this->config->get($id)) && $this->container->has($this->config->get($id))) {
            $this->instances->set($id, $this->container->get($this->config->get($id)));
            return;
        }
    }
}
