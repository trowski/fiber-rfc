# PHP RFC: Fibers 
  * Version: 0.1
  * Date: 2020-09-04
  * Author: Aaron Piotrowski <trowski@php.net>
  * Status: Draft
  * First Published at: http://wiki.php.net/rfc/fibers

## Introduction 

For a long time since PHP was created, most people have written PHP as synchronous code. They have code that runs synchronously and in turn, only calls functions that run synchronously.

More recently, there have been multiple projects that have allowed people to write asynchronous PHP code. That is code that can be called asynchronously, and can call functions that either run synchronously or asynchronously. Examples of these projects are [AMPHP](https://amphp.org/), [ReactPHP](https://reactphp.org/), and [Guzzle](https://guzzlephp.org/).

The problem this RFC seeks to address is a difficult one to explain, but can be referred to as the ["What color is your function?"](https://journal.stuffwithstuff.com/2015/02/01/what-color-is-your-function/) problem. That link contains a detailed explanation of the problem.

A summary is that:

* Asynchronous functions need to be called in a special way.
* It's easier to call synchronous functions.
* All PHP core functions are synchronous.
* If there is any asynchronous code in the call stack, then any call to a synchronous function 'breaks' the asynchronous code.

For people who are familiar with using promises and await to achieve writing asynchronous code, it can be expressed as; once one function returns a promise somewhere in your call stack, the entire call stack needs to return a promise because the result of the call cannot be known until the promise is resolved.

This RFC seeks to solve this problem by allowing functions to be interruptible without polluting the entire call stack. This would be achieved by:

 * adding support for [Fibers](https://en.wikipedia.org/wiki/Fiber_(computer_science)) to PHP
 * adding `Fiber` and `Continuation` classes, and an interface `FiberScheduler`
 * adding exception classes `FiberError` and `FiberExit` to represent errors

### Definition of terms

To allow better understanding of the RFC, this section defines what the names Fibers, Continuation, and FiberScheduler mean for the RFC being proposed.

### Fibers

Fibers allow you to create full-stack, interruptible functions that can be used to implement cooperative concurrency in PHP. These are also known as coroutines or green-threads.

Unlike stack-less Generators, each Fiber contains a call stack, allowing them to be paused within deeply nested function calls. A function declaring an interruption point (i.e., calling `Fiber::suspend()`) need not change its return type, unlike a function using `yield` which must return a `Generator` instance.

Fibers pause the entire execution stack, so the direct caller of the function does not need to change how it invokes the function.

A fiber could be created with any callable and variadic argument list through `Fiber::run(callable $callback, mixed ...$args)`.

The callable may use `Fiber::suspend()` to interrupt execution anywhere in the call stack (that is, the call to `Fiber::suspend()` may be in a deeply nested function or not even exist at all).

### Continuation 

A continuation allows resuming a suspended fiber upon completion of an asynchronous operation.

A fiber is resumed with any value using `Continuation::resume()` or by throwing an exception into the fiber using `Continuation::throw()`. The value is returned (or exception thrown) from `Fiber::suspend()`. A continuation may only be used a single time.

### FiberScheduler

A `FiberScheduler` is a class which is able to: 

* create new fibers and resume suspended fibers 
* respond to events

Generally a fiber scheduler can be thought of as an event loop that responds to events on sockets, timers, and deferred functions.

This RFC does not include an implementation for `FiberScheduler`. Instead, it proposes only defining an interface and any implementation would be done in user code (see [Future Scope](#future-scope)).

## Proposal

#### Fiber 

A Fiber would be represented as class which would be defined in core PHP. It would have the following signature:

``` php
final class Fiber
{
    /**
     * Can only be called within {@see FiberScheduler::run()}.
     *
     * @param callable $callback Function to invoke when starting the Fiber.
     * @param mixed ...$args Function arguments.
     */
    public static function run(callable $callback, mixed ...$args): void { }

    /**
     * Suspend execution of the fiber. A Continuation object is provided as the first argument to the given callback.
     * The fiber may be resumed with {@see Continuation::resume()} or {@see Continuation::throw()}.
     *
     * @param callable(Continuation):void $enqueue
     * @param FiberScheduler $scheduler
     *
     * @return mixed Value provided to {@see Continuation::resume()}.
     *
     * @throws FiberError Thrown if within {@see FiberScheduler::run()}.
     * @throws Throwable Exception provided to {@see Continuation::throw()}.
     */
    public static function suspend(callable $enqueue, FiberScheduler $scheduler): mixed { }

    /**
     * Private constructor to force use of {@see run()}.
     */
    private function __construct() { }
}
```

`Fiber::suspend()` accepts a callback that is provided an instance of `Continuation` as the first argument. This object is used to resume the fiber with any value or throw an exception into the fiber.

#### Continuation

``` php
final class Continuation
{
    /**
     * @return bool True if either {@see resume()} or {@see throw()} has been called previously.
     */
    public function continued(): bool { }

    /**
     * Resumes the fiber, returning the given value from {@see Fiber::suspend()}.
     *
     * @param mixed $value
     *
     * @throw FiberError If the continuation has already been used.
     */
    public function resume(mixed $value = null): void { }

    /**
     * Throws the given exception into the fiber from {@see Fiber::suspend()}.
     *
     * @param Throwable $exception
     *
     * @throw FiberError If the continuation has already been used.
     */
    public function throw(Throwable $exception): void { }

    /**
     * Cannot be constructed by user code.
     */
    private function __construct() { }
}
```

A continuation resumes a suspended fiber in one of two ways:

 * returning a value from `Fiber::suspend()` using `Continuation::resume()`
 * throwing an exception from `Fiber::suspend()` using `Continuation::throw()`

After one of these two methods is called, the continuation has been used and cannot be used again. `Continuation::continued()` will return `true` after calling either method.

If the `Continuation` object is destroyed before calling either method, the associated fiber also will be destroyed. (see [Unfinished Fibers](#unfinished-fibers)).

#### FiberScheduler 

``` php
interface FiberScheduler
{
    /**
     * Run the scheduler.
     */
    public function run(): void;
}
```

A `FiberScheduler` defines a special class is able to create new fibers using `Fiber::run()` and resume fibers using `Continuation` objects. In general, a fiber scheduler would be an event loop that responds to events on sockets, timers, and deferred functions.

When an instance of `FiberScheduler` is provided to `Fiber::suspend()` for the first time, internally a fiber is created for that instance and invokes `FiberScheduler::run()`. The fiber created is paused when resuming a fiber and again resumed when the same instance of `FiberScheduler` is provided to another call to `Fiber::suspend()`. It is expected that `FiberScheduler::run()` not return until all pending events have been processed and any suspended fibers have been resumed. In practice this is not difficult, as the scheduler is paused when resuming a fiber and only re-entered upon a fiber suspending that creates more events in the scheduler.

`FiberScheduler::run()` throwing an exception results in an uncaught exception and exits the script.

A fiber *must* be resumed from the fiber created from the instance of `FiberScheduler` provided to `Fiber::suspend()`. Doing otherwise results in a fatal error. In practice this means that calling `Continuation::resume()` or `Continuation::throw()` must be within a callback registered to an event handled within a `FiberScheduler` instance. Often it is desirable to ensure resumption of a fiber is asynchronous, making it easier to reason about program state before and after an event would resume a fiber.

#### Unfinished Fibers

Fibers that are not finished (do not complete execution) are destroyed similarly to unfinished generators, executing any pending `finally` blocks. `Fiber::suspend()` may not be invoked in a force-closed fiber, just as `yield` cannot be used in a force-closed generator.

## Backward Incompatible Changes

Declares `Continuation`, `Fiber`, `FiberScheduler`, `FiberError`, and `FiberExit` in the root namespace. No other BC breaks.

## Proposed PHP Version(s)

PHP 8.1

## Future Scope

#### async/await keywords

Using an internally defined `FiberScheduler` and an additionally defined `Awaitable` object, `Fiber::suspend()` could be replaced with the keyword `await` and new fibers could be created using the keyword `async`. The usage of `async` differs slightly from languages such as JS or Hack. `async` is not used to declare asynchronous functions, rather it is used at call time to modify the call to any function or method to return an awaitable and start a new fiber (green-thread).

An `Awaitable` would act like a promise, representing the future result of the fiber created with `async`.

``` php
$awaitable = async functionOrMethod();
// async modifies the call to return an Awaitable, creating a new fiber, so execution continues immediately.
await $awaitable; // Await the function result at a later point.
```

These keywords can be created in user code using the proposed fiber API. [AMPHP v3](https://github.com/amphp/amp/tree/v3) (a work-in-progress) defines [`await()` and `async()`](https://github.com/amphp/amp/blob/6d5e0f5ff73a7ffb47243a491fd09b1d57930d23/lib/functions.php#L6-L61) functions to await `Amp\Promise` instances and create new coroutines.

#### defer keyword 

Fibers may be used to implement a `defer` keyword that executes a statement sometime after the current scope is exited within a new fiber. Such a keyword would also require an internal implementation of `FiberScheduler` and likely would be an addition after async/await keywords.

This keyword also can be created in user code using the proposed fiber API, an example being [`defer()`](https://github.com/amphp/amp/blob/6d5e0f5ff73a7ffb47243a491fd09b1d57930d23/lib/functions.php#L77-L87) in AMPHP v3.

## Proposed Voting Choices 

Merge implementation into core, 2/3 required.

## Patches and Tests 

Implementation at [amphp/ext-fiber](https://github.com/amphp/ext-fiber).

[AMPHP v3](https://github.com/amphp/amp/tree/v3), a work-in-progress, uses `ext-fiber`. Nearly all libraries under the GitHub organization [amphp](https://github.com/amphp) have branches compatible with AMPHP v3. The branches are labeled as `vX`, where `X` is the current version + 1 (for example, the `v5` branch of [amphp/http-client](https://github.com/amphp/http-client/tree/v5)). See the `examples` directories in various libraries for samples of PHP code using fibers.

## References 
  * [Boost C++ fibers](https://www.boost.org/doc/libs/1_67_0/libs/fiber/doc/html/index.html)
  * [Ruby Fibers](https://ruby-doc.org/core-2.5.0/Fiber.html)
  * [Lua Fibers](https://wingolog.org/archives/2018/05/16/lightweight-concurrency-in-lua)
  * [Project Loom for Java](https://cr.openjdk.java.net/~rpressler/loom/Loom-Proposal.html)
