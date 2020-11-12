# PHP RFC: Fibers 
  * Version: 0.1
  * Date: 2020-11-11
  * Author: Aaron Piotrowski <trowski@php.net>
  * Status: Draft
  * First Published at: http://wiki.php.net/rfc/fibers

## Introduction 

For most of PHP's history, people have written PHP code only as synchronous code. They have code that runs synchronously and in turn, only calls functions that run synchronously. Synchronous functions stop execution until a result is available to return from the function.

More recently, there have been multiple projects that have allowed people to write asynchronous PHP code.  Asynchronous functions accept a callback or return a placeholder for a future value (such as a promise) to run code at a future time once the result is available. Execution continues without waiting for a result. Examples of these projects are [AMPHP](https://amphp.org/), [ReactPHP](https://reactphp.org/), and [Guzzle](https://guzzlephp.org/).

The problem this RFC seeks to address is a difficult one to explain, but can be referred to as the ["What color is your function?"](https://journal.stuffwithstuff.com/2015/02/01/what-color-is-your-function/) problem. That link contains a detailed explanation of the problem.

A summary of the problem described in the linked article is:

* Asynchronous functions change the way the function must be called.
* Synchronous functions may not call an asynchronous function (though asynchronous functions may call synchronous functions).
* Calling an asynchronous function requires the entire callstack to be asynchronous

For people who are familiar with using promises and await/yield to achieve writing asynchronous code, the problem can be expressed as: "Once one function returns a promise somewhere in your call stack, the entire call stack needs to return a promise because the result of the call cannot be known until the promise is resolved."

This RFC seeks to eliminate the distiction between synchronous and asynchronous functions by allowing functions to be interruptible without polluting the entire call stack. This would be achieved by:

 * Adding support for [Fibers](https://en.wikipedia.org/wiki/Fiber_(computer_science)) to PHP.
 * Adding `Fiber` and `Continuation` classes, and an interface `FiberScheduler`.
 * Adding exception classes `FiberError` and `FiberExit` to represent errors.

### Definition of terms

To allow better understanding of the RFC, this section defines what the names Fiber, Continuation, and FiberScheduler mean for the RFC being proposed.

#### Fibers

Fibers allow you to create full-stack, interruptible functions that can be used to implement cooperative concurrency in PHP. These are also known as coroutines or green-threads.

Unlike stack-less Generators, each Fiber contains a call stack, allowing them to be paused within deeply nested function calls. A function declaring an interruption point (i.e., calling `Fiber::suspend()`) need not change its return type, unlike a function using `yield` which must return a `Generator` instance.

Fibers pause the entire execution stack, so the direct caller of the function does not need to change how it invokes the function.

A fiber could be created with any callable and variadic argument list through `Fiber::run(callable $callback, mixed ...$args)`.

The callable may use `Fiber::suspend()` to interrupt execution anywhere in the call stack (that is, the call to `Fiber::suspend()` may be in a deeply nested function or not even exist at all).

This proposal treats `{main}` as a fiber, allowing `Fiber::suspend()` to be called from the top-level context.

Fibers can be suspended in *any* function call, including those called from within the PHP VM, such as functions provided to `array_map` or methods called by `foreach` on an `Iterator` object.

#### Continuation 

A continuation allows resuming a suspended fiber upon completion of an asynchronous operation.

A fiber is resumed with any value using `Continuation::resume()` or by throwing an exception into the fiber using `Continuation::throw()`. The value is returned (or exception thrown) from `Fiber::suspend()`. A continuation may only be used a single time.

#### FiberScheduler

A `FiberScheduler` is able to create new fibers and resume suspended fibers. A fiber scheduler will generally act as an event loop, responding to events on sockets, timers, and deferred functions. When a fiber is suspended, execution switches into the fiber scheduler to await events or resume other suspended fibers.

## Proposal

#### Fiber 

A Fiber would be represented as class which would be defined in core PHP with the following signature:

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

`Fiber::suspend()` accepts a callback that is provided an instance of `Continuation` as the first argument. The `Continuation` object may be used at a later time to resume the fiber with any value or throw an exception into the fiber. The callback is invoked within the running fiber before it is suspended. The callback should create event watchers in the `FiberScheduler` instance (event loop), add the fiber to a list of pending fibers, or otherwise set up logic that will resume the fiber at a later time from the instance of `FiberScheduler` provided to `Fiber::suspend()`.

#### Continuation

A continuation would be represented by a class which would be fined in core PHP with the following signature:

``` php
final class Continuation
{
    /**
     * @return bool True if the continuation is still pending, that is, if neither {@see resume()} or {@see throw()}
     *              has been called.
     */
    public function isPending(): bool { }

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

A `Continuation` object is created when suspending a fiber and may be used to resume the suspended fiber in one of two ways:

 * returning a value from `Fiber::suspend()` using `Continuation::resume()`
 * throwing an exception from `Fiber::suspend()` using `Continuation::throw()`

After one of these two methods is called, the continuation has been used and cannot be used again. `Continuation::isPending()` will return `false` after calling either method.

If the `Continuation` object is destroyed before calling either method, the associated fiber also will be destroyed as there is no longer a way to resume the fiber. (see [Unfinished Fibers](#unfinished-fibers)).

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

A `FiberScheduler` defines a class is able to create new fibers using `Fiber::run()` and resume fibers using `Continuation` objects. In general, a fiber scheduler would be an event loop that responds to events on sockets, timers, and deferred functions.

When an instance of `FiberScheduler` is provided to `Fiber::suspend()` for the first time, internally a new fiber (a scheduler fiber) is created for that instance and invokes `FiberScheduler::run()`. The scheduler fiber created is paused when resuming another fiber and again resumed when the same instance of `FiberScheduler` is provided to another call to `Fiber::suspend()`. It is expected that `FiberScheduler::run()` will not return until all pending events have been processed and any suspended fibers have been resumed. In practice this is not difficult, as the scheduler fiber is paused when resuming a fiber and only re-entered upon a fiber suspending that creates more events in the scheduler.

`FiberScheduler::run()` throwing an exception results in an uncaught exception and exits the script.

A fiber *must* be resumed from the fiber created from the instance of `FiberScheduler` provided to `Fiber::suspend()`. Doing otherwise results in a fatal error. In practice this means that calling `Continuation::resume()` or `Continuation::throw()` must be within a callback registered to an event handled within a `FiberScheduler` instance. Often it is desirable to ensure resumption of a fiber is asynchronous, making it easier to reason about program state before and after an event would resume a fiber.

When a script ends, each fiber scheduler used in the a script is resumed and allowed to run to completion to complete unfinished tasks or free resources.

This RFC does not include an implementation for `FiberScheduler`. Instead, it proposes only defining an interface and any implementation would be done in user code (see [Future Scope](#future-scope)).

#### Unfinished Fibers

Fibers that are not finished (do not complete execution) are destroyed similarly to unfinished generators, executing any pending `finally` blocks. `Fiber::suspend()` may not be invoked in a force-closed fiber, just as `yield` cannot be used in a force-closed generator. Fibers are destroyed when there are no references to the `Continuation` object created from the last suspend point. An exception to this is the `{main}` fiber, where removing all references to the `Continuation` object that resumes the main fiber will result in a `FiberExit` exception to be thrown from the call to `Fiber::suspend()`, resulting in a fatal error.

#### Fiber Stacks

Each fiber is allocated a separate C stack and VM stack. The stack is allocated using `mmap` if available, meaning physical memory is used only on demand (if it needs to be allocated to a stack value) on most platforms. Each fiber stack is allocated 1M maximum of memory by default, settable with a ini setting `fiber.stack_size`. Note that this memory is used for the C stack and is not related to the memory available to PHP code. VM stacks for each fiber are allocated in a similar way to generators and use a similar amount of memory and CPU.

## FAQ

#### Who is the target audience for this feature?

Fibers are an advanced feature that most users will not use directly. This feature is primarily targeted at library and framework authors to provide an event loop and an asynchronous programming API. Fibers allow integrating asynchronous code execution seamlessly into synchronous code at any point without the need to modify the application call stack or add boilerplate code.

`FFI` is an example of a feature recently added to PHP that most users may not use directly, but can benefit from greatly within libraries they use.

#### Why use `FiberScheduler` instead of an API similar to Lua or Ruby?

Using a fiber scheduler instead of a generator-like API enables a few features:

 * Suspension of the top-level (`{main}`): When the main fiber is suspended, execution continues into the fiber scheduler.
 * Nesting schedulers: A fiber may suspend into different fiber schedulers at various suspension points. Each scheduler will be started/suspended/resumed as needed. While a fully asynchronous app may want to ensure it does not use multiple fiber schedulers, a FPM application may find it acceptable to do so.
 * Elimination of boilerplate: Suspending at the top-level eliminates the need for an application to wrap code into a library-specific scheduler, allowing library code to suspend and resume as needed.

#### What about performance?

Switching between fibers is lightweight, requiring changing the value of approximately 20 pointers, give or take, depending on platform. Switching context in the VM is similar to Generators. Since fibers exist within a single process thread, switching between fibers is significantly more performant than switching between processes/threads.

#### What platforms are supported?

Fibers are supported on nearly all modern CPU architectures, including x86, x86_64, i386, ARM (32 and 64-bit), PPC (32 and 64-bit), MIPS, Windows, and *nix platforms with ucontext. Support for C stack switching is provided by Boost, which has a [license](https://www.boost.org/LICENSE_1_0.txt) that allows components to be distributed directly with PHP.

#### How is a `FiberScheduler` implemented?

A `FiberScheduler` is like any other PHP class implementing an interface. The `run()` method may contain any necessary code to resume suspended fibers for the given application. Generally, a `FiberScheduler` would loop through available events, resuming fibers when an event occurs. The `ext-fiber` repo contains a [very simple implementation](https://github.com/amphp/ext-fiber/blob/395bf3f66805d0d41363c82be142698093ff3348/scripts/Loop.php) that is able to delay functions for a given number of milliseconds or until the scheduler is entered again. This simple implementation is used in the [`ext-fiber` phpt tests](https://github.com/amphp/ext-fiber/tree/395bf3f66805d0d41363c82be142698093ff3348/tests).

#### How does blocking code affect fibers

Blocking code (such as `file_get_contents()`) will continue to block the entire process, even if other fibers exist. Code must be written to use asynchonous I/O, an event loop, and fibers to see a performance and concurrency benefit. As mentioned in the introduction, several libraries already exist for asynchronous I/O and can take advantage of fibers to integrate with synchronous code while expanding the potential for concurrency in an application.

#### Why add this to PHP core?

Putting this capability directly in PHP core makes it widely available on any host providing PHP. Often users are not able to determine what extensions may be available in a particular hosting environment, are unsure of how to install extensions, or do not want to install 3rd-party extensions. Adding this feature directly to PHP core makes allows it to be used by a wide variety of library authors without concerns of portability.

Further, the extension currently forbids suspending in shutdown functions and destructors executed during shutdown. However, there is no technical reason for this other than the hooks provided by PHP for extensions. Adding the fibers to PHP core would allow the engine to finish executing fiber schedulers after registered shutdown functions are invoked.

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

Fibers may be used to implement a `defer` keyword that executes a statement within a new fiber when the current fiber is suspended or terminates. Such a keyword would also require an internal implementation of `FiberScheduler` and likely would be an addition after async/await keywords.

This keyword also can be created in user code using the proposed fiber API, an example being [`defer()`](https://github.com/amphp/amp/blob/6d5e0f5ff73a7ffb47243a491fd09b1d57930d23/lib/functions.php#L77-L87) in AMPHP v3.

## Proposed Voting Choices 

Merge implementation into core, 2/3 required.

## Patches and Tests 

Implementation and tests at [amphp/ext-fiber](https://github.com/amphp/ext-fiber).

[AMPHP v3](https://github.com/amphp/amp/tree/v3), a work-in-progress, uses `ext-fiber`. Nearly all libraries under the GitHub organization [amphp](https://github.com/amphp) have branches compatible with AMPHP v3. The branches are labeled as `vX`, where `X` is the current version + 1 (for example, the `v5` branch of [amphp/http-client](https://github.com/amphp/http-client/tree/v5)). See the `examples` directories in various libraries for samples of PHP code using fibers.

[React Fiber](https://github.com/trowski/react-fiber) uses `ext-fiber` to create coroutines and await any instance of `React\Promise\PromiseInterface` until it is resolved.

## Examples

The example below uses a [simple implemenation of a `FiberScheduler`](https://github.com/amphp/ext-fiber/blob/395bf3f66805d0d41363c82be142698093ff3348/scripts/Loop.php) to delay execution of a function for 1000 milliseconds. The function is scheduled when the fiber is suspended with `Fiber::suspend()`. When this function is invoked, the fiber is resumed with the value given to `Continuation::resume()`.

``` php
$loop = new Loop;

$value = Fiber::suspend(function (Continuation $continuation) use ($loop): void {
	$loop->delay(1000, fn() => $continuation->resume(1));
}, $loop);

var_dump($value); // int(1)
```

While a contrived example, imagine if the fiber was awaiting data on a network socket or the result of a database query. Combine this with the ability to simultaneously run and suspend many fibers allows a single PHP process to concurrently await many events.

The next example uses the async framework [AMPHP v3](https://github.com/amphp/amp/tree/v3) mentioned in [Patches and Tests](#patches-and-tests) to demonstrate how fibers may be used by frameworks to create asynchronous that is written like synchronous code. Note that the `Delayed` object is a promise-like object that resolves itself with the second argument after the number of milliseconds given as the first argument. The `await()` function suspends a fiber until the promise is resolved and the `async()` function creates a new fiber, returning a promise that is resolved when the fiber completes, allowing multiple fibers to be executed concurrently.

``` php
use Amp\Delayed;
use Amp\Loop;
use function Amp\async;
use function Amp\await;

// Note that the closure declares int as a return type, not Promise or Generator, but executes like a coroutine.
$callback = function (int $id): int {
    return await(new Delayed(1000, $id)); // Await promise resolution.
};

$timer = Loop::repeat(100, function (): void {
    echo ".", PHP_EOL; // This repeat timer is to show the event loop is not being blocked.
});
Loop::unreference($timer); // Unreference timer so the loop exits automatically when all tasks complete.

// Invoking $callback returns an int, but is executed asynchronously.
$result = $callback(1); // Call a subroutine within this green thread, taking 1 second to return.
\var_dump($result);

// Simultaneously runs two new green threads, await their resolution in this green thread.
$result = await([  // Executed simultaneously, only 1 second will elapse during this await.
    async($callback, 2),
    async($callback, 3),
]);
\var_dump($result); // Executed after 2 seconds.

$result = $callback(4); // Call takes 1 second to return.
\var_dump($result);

// array_map() takes 2 seconds to execute as the calls are not concurrent, but this shows that fibers are
// supported by internal callbacks.
$result = \array_map($callback, [5, 6]);
\var_dump($result);
```

Since fibers can be paused during calls within the PHP VM, fibers can also be used to create asynchronous iterators. The example below again uses AMPHP v3, creating a `Pipeline`, an iterator-like object that implements `Traversable`, allowing it to be used with `foreach` and `yield from` to iterate over an asynchronous set of values. `PipelineSource` is used to emit values as they are generated. The `foreach` loop will suspend while waiting for another value from the pipeline.

``` php
use Amp\Delayed;
use Amp\PipelineSource;
use function Amp\defer;
use function Amp\await;

$source = new PipelineSource;
$pipeline = $source->pipe();

// defer() runs the given function in a separate fiber.
defer(function (PipelineSource $source): void {
    $source->yield(await(new Delayed(500, 1)));
    $source->yield(await(new Delayed(1500, 2)));
    $source->yield(await(new Delayed(1000, 3)));
    $source->yield(await(new Delayed(2000, 4)));
    $source->yield(5);
    $source->yield(6);
    $source->yield(7);
    $source->yield(await(new Delayed(2000, 8)));
    $source->yield(9);
    $source->yield(await(new Delayed(1000, 10));
    $source->complete();
}, $source);

foreach ($pipeline as $value) {
    \printf("Pipeline source yielded %d\n", $value);
}
```

## References 
  * [Boost C++ fibers](https://www.boost.org/doc/libs/1_67_0/libs/fiber/doc/html/index.html)
  * [Ruby Fibers](https://ruby-doc.org/core-2.5.0/Fiber.html)
  * [Lua Fibers](https://wingolog.org/archives/2018/05/16/lightweight-concurrency-in-lua)
  * [Project Loom for Java](https://cr.openjdk.java.net/~rpressler/loom/Loom-Proposal.html)
