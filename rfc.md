# PHP RFC: Fibers 

  * Date: 2020-11-28
  * Author: Aaron Piotrowski <trowski@php.net>
  * Status: Draft
  * First Published at: http://wiki.php.net/rfc/fibers

## Introduction 

For most of PHP's history, people have written PHP code only as synchronous code. They have code that runs synchronously and in turn, only calls functions that run synchronously. Synchronous functions stop execution until a result is available to return from the function.

More recently, there have been multiple projects that have allowed people to write asynchronous PHP code.  Asynchronous functions accept a callback or return a placeholder for a future value (such as a promise) to run code at a future time once the result is available. Execution continues without waiting for a result. Examples of these projects are [AMPHP](https://amphp.org/), [ReactPHP](https://reactphp.org/), and [Guzzle](https://guzzlephp.org/).

The problem this RFC seeks to address is a difficult one to explain, but can be referred to as the ["What color is your function?"](https://journal.stuffwithstuff.com/2015/02/01/what-color-is-your-function/) problem.

A summary of the problem described in the linked article is:

* Asynchronous functions change the way the function must be called.
* Synchronous functions may not call an asynchronous function (though asynchronous functions may call synchronous functions).
* Calling an asynchronous function requires the entire callstack to be asynchronous

For people who are familiar with using promises and await/yield to achieve writing asynchronous code, the problem can be expressed as: "Once one function returns a promise somewhere in your call stack, the entire call stack needs to return a promise because the result of the call cannot be known until the promise is resolved."

This RFC seeks to eliminate the distiction between synchronous and asynchronous functions by allowing functions to be interruptible without polluting the entire call stack. This would be achieved by:

 * Adding support for [Fibers](https://en.wikipedia.org/wiki/Fiber_(computer_science)) to PHP.
 * Adding `Fiber`, `ReflectionFiber`, and `ReflectionFiberScheduler` classes, and an interface `FiberScheduler`.
 * Adding exception classes `FiberError` and `FiberExit` to represent errors.

### Definition of terms

To allow better understanding of the RFC, this section defines what the names Fiber and FiberScheduler mean for the RFC being proposed.

#### Fibers

Fibers allow you to create full-stack, interruptible functions that can be used to implement cooperative concurrency in PHP. These are also known as coroutines or green-threads.

Unlike stack-less Generators, each Fiber contains a call stack, allowing them to be paused within deeply nested function calls. A function declaring an interruption point (i.e., calling `Fiber::suspend()`) need not change its return type, unlike a function using `yield` which must return a `Generator` instance.

Fibers pause the entire execution stack, so the direct caller of the function does not need to change how it invokes the function.

Execution may be interrupted anywhere in the call stack using  `Fiber::suspend()` (that is, the call to `Fiber::suspend()` may be in a deeply nested function or not even exist at all).

This proposal treats `{main}` as a fiber, allowing `Fiber::suspend()` to be called from the top-level context.

Fibers can be suspended in *any* function call, including those called from within the PHP VM, such as functions provided to `array_map` or methods called by `foreach` on an `Iterator` object.

Once suspended, execution of the fiber may be resumed with any value using `Fiber->resume()` or by throwing an exception into the fiber using `Fiber->throw()`. The value is returned (or exception thrown) from `Fiber::suspend()`.

#### FiberScheduler

A `FiberScheduler` is able to start new fibers and resume suspended fibers. A fiber scheduler will generally act as an event loop, responding to events on sockets, timers, and deferred functions. When a fiber is suspended, execution switches into the fiber scheduler to await events or resume other suspended fibers.

## Proposal

### Fiber 

A Fiber would be represented as class which would be defined in core PHP with the following signature:

``` php
final class Fiber
{
    /**
     * @param callable $callback Function to invoke when running the fiber.
     */
    public static function create(callable $callback): Fiber { }

    /**
     * Starts execution of the fiber. Returns when the fiber suspends or terminates.
     *
     * Must be called within {@see FiberScheduler::run()}.
     *
     * @param mixed ...$args Arguments passed to fiber function.
     */
    public function start(mixed ...$args): void { }

    /**
     * Resumes the fiber, returning the given value from {@see Fiber::suspend()}.
     * Returns when the fiber suspends or terminates.
     *
     * Must be called within {@see FiberScheduler::run()}.
     *
     * @param mixed $value
     *
     * @throw FiberError If the fiber is running or terminated.
     */
    public function resume(mixed $value = null): void { }

    /**
     * Throws the given exception into the fiber from {@see Fiber::suspend()}.
     * Returns when the fiber suspends or terminates.
     *
     * Must be called within {@see FiberScheduler::run()}.
     *
     * @param Throwable $exception
     *
     * @throw FiberError If the fiber is running or terminated.
     */
    public function throw(Throwable $exception): void { }

    /**
     * @return bool True if the fiber is suspended.
     */
    public function isSuspended(): bool { }

    /**
     * @return bool True if the fiber is currently running.
     */
    public function isRunning(): bool { }

    /**
     * @return bool True if the fiber has completed execution.
     */
    public function isTerminated(): bool { }

    /**
     * Suspend execution of the fiber. The Fiber object is provided as the first argument to the given callback.
     * The fiber may be resumed with {@see Fiber::resume()} or {@see Fiber::throw()}.
     *
     * Cannot be called within {@see FiberScheduler::run()}.
     *
     * @param callable(Fiber):void $enqueue
     * @param FiberScheduler $scheduler
     *
     * @return mixed Value provided to {@see Fiber::resume()}.
     *
     * @throws FiberError Thrown if within {@see FiberScheduler::run()} or within a callback given to this method.
     * @throws Throwable Exception provided to {@see Fiber::throw()}.
     */
    public static function suspend(callable $enqueue, FiberScheduler $scheduler): mixed { }

    /**
     * Private constructor to force use of {@see create()}.
     */
    private function __construct() { }
}
```

A `Fiber` object is created using `Fiber::create(callable $callback)` with any callable. The callable need not call `Fiber::suspend()` directly, it may be in a deeply nested call, far down the call stack (or perhaps never call `Fiber::suspend()` at all). The returned `Fiber` may be started within a `FiberScheduler` (discussed below) using `Fiber->start(mixed ...$args)` with a variadic argument list that is provided as arguments to the callable used when creating the `Fiber`.

`Fiber::suspend()` accepts a callback that is provided an instance of `Fiber` as the first argument. The `Fiber` object may be used at a later time to resume the fiber with any value or throw an exception into the fiber. The callback is invoked within the running fiber before it is suspended. The callback should create event watchers in the `FiberScheduler` instance (event loop), add the fiber to a list of pending fibers, or otherwise set up logic that will resume the fiber at a later time from the instance of `FiberScheduler` provided to `Fiber::suspend()`.

A suspended fiber may be resumed in one of two ways:

 * returning a value from `Fiber::suspend()` using `Fiber->resume()`
 * throwing an exception from `Fiber::suspend()` using `Fiber->throw()`

### FiberScheduler 

``` php
interface FiberScheduler
{
    /**
     * Run the scheduler, scheduling and responding to events.
     * This method should not return until no futher pending events remain in the fiber scheduler.
     */
    public function run(): void;
}
```

A `FiberScheduler` defines a class which is able to start new fibers using `Fiber->start()` and resume fibers using `Fiber->resume()` and `Fiber->throw()`. In general, a fiber scheduler would be an event loop that responds to events on sockets, timers, and deferred functions.

When an instance of `FiberScheduler` is provided to `Fiber::suspend()` for the first time, internally a new fiber (a scheduler fiber) is created for that instance and invokes `FiberScheduler->run()`. The scheduler fiber created is suspended when resuming or starting another fiber (that is, when calling `Fiber->start()`, `Fiber->resume()`, or `Fiber->throw()`) and again resumed when the same instance of `FiberScheduler` is provided to another call to `Fiber::suspend()`. It is expected that `FiberScheduler->run()` will not return until all pending events have been processed and any suspended fibers have been resumed. In practice this is not difficult, as the scheduler fiber is suspended when resuming a fiber and only re-entered upon a fiber suspending which will create more events in the scheduler.

If a scheduler completes (that is, returns from `FiberScheduler->run()`) without resuming the suspended fiber, an instance of `FiberError` is thrown from the call to `Fiber::suspend()`.

If a `FiberScheduler` instance whose associated fiber has completed is later reused in a call to `Fiber::suspend()`, `FiberScheduler->run()` will be invoked again to create a new fiber associated with that `FiberScheduler` instance.

`FiberScheduler->run()` throwing an exception results in an uncaught `FiberExit` exception and exits the script.

A fiber *must* be resumed from within the instance of `FiberScheduler` provided to `Fiber::suspend()`. Doing otherwise results in a fatal error. In practice this means that calling `Fiber->resume()` or `Fiber->throw()` must be within a callback registered to an event handled within a `FiberScheduler` instance. Often it is desirable to ensure resumption of a fiber is asynchronous, making it easier to reason about program state before and after an event would resume a fiber.

When a script ends, each scheduler fiber created from a call to  `FiberScheduler->run()` is resumed and allowed to run to completion to complete unfinished tasks or free resources.

This RFC does not include an implementation for `FiberScheduler`. Instead, it proposes only defining an interface and any implementation would be done in user code (see [Future Scope](#future-scope)).

### ReflectionFiber

`ReflectionFiber` is used to inspect executing fibers. A `ReflectionFiber` object can be created from any `Fiber` object, even if it has not been started or if it has been finished. This reflection class is similar to `ReflectionGenerator`.

``` php
class ReflectionFiber
{
    /**
     * @param Fiber $fiber Any Fiber object, including those that are not started or have
     *                     terminated.
     */
    public function __construct(Fiber $fiber) { }

    /**
     * @return string Current file of fiber execution.
     *
     * @throws ReflectionException If the fiber has not been started or has terminated.
     */
    public function getExecutingFile(): string { }

    /**
     * @return int Current line of fiber execution.
     *
     * @throws ReflectionException If the fiber has not been started or has terminated.
     */
    public function getExecutingLine(): int { }

    /**
     * @param int $options Same flags as {@see debug_backtrace()}.
     *
     * @return array Fiber backtrace, similar to {@see debug_backtrace()}
     *               and {@see ReflectionGenerator::getTrace()}.
     *
     * @throws ReflectionException If the fiber has not been started or has terminated.
     */
    public function getTrace(int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT): array { }

    /**
     * @return bool True if the fiber is currently suspended, false otherwise.
     */
    public function isSuspended(): bool { }

    /**
     * @return bool True if the fiber is currently running, false otherwise.
     */
    public function isRunning(): bool { }

    /**
     * @return bool True if the fiber has completed execution (either returning or
     *              throwing an exception), false otherwise.
     */
    public function isTerminated(): bool { }
}
```

### ReflectionFiberScheduler

`ReflectionFiberScheduler` is used to inspect the internal fibers created from objects implementing `FiberScheduler` after being used to suspend a fiber.

``` php
class ReflectionFiberScheduler extends ReflectionFiber
{
    /**
     * @param FiberScheduler $scheduler
     *
     * @throws ReflectionException If the {@see FiberScheduler} has not been used to suspend a fiber.
     */
    public function __construct(FiberScheduler $scheduler) { }

    /**
     * @return FiberScheduler The instance used to create the fiber.
     */
    public function getFiberScheduler(): FiberScheduler { }
}
```

#### Unfinished Fibers

Fibers that are not finished (do not complete execution) are destroyed similarly to unfinished generators, executing any pending `finally` blocks. `Fiber::suspend()` may not be invoked in a force-closed fiber, just as `yield` cannot be used in a force-closed generator. Fibers are destroyed when there are no references to the `Fiber` object. An exception to this is the `{main}` fiber, where removing all references to the `Fiber` object that resumes the main fiber will result in a `FiberExit` exception to be thrown from the call to `Fiber::suspend()`, resulting in a fatal error.

#### Fiber Stacks

Each fiber is allocated a separate C stack and VM stack. The C stack is allocated using `mmap` if available, meaning physical memory is used only on demand (if it needs to be allocated to a stack value) on most platforms. Each fiber stack is allocated 1M maximum of memory by default, settable with a ini setting `fiber.stack_size`. Note that this memory is used for the C stack and is not related to the memory available to PHP code. VM stacks for each fiber are allocated in a similar way to generators and use a similar amount of memory and CPU.

## FAQ

#### Who is the target audience for this feature?

Fibers are an advanced feature that most users will not use directly. This feature is primarily targeted at library and framework authors to provide an event loop and an asynchronous programming API. Fibers allow integrating asynchronous code execution seamlessly into synchronous code at any point without the need to modify the application call stack or add boilerplate code.

`FFI` is an example of a feature recently added to PHP that most users may not use directly, but can benefit from greatly within libraries they use.

#### Why use `FiberScheduler` instead of an API similar to Lua or Ruby?

Fibers require a scheduler to be useful. A scheduler is responsible for creating and resuming fibers. A fiber on it's own does nothing – something external to the fiber must control it. This is not unlike generators. When you iterate over a generator using `foreach`, you are using a "scheduler" to control the generator. If you write code using the `send()` or `throw()` methods of a generator, you are writing a generator scheduler. However, because generators are stack-less and can only yield from their immediate context, the author of generator has direct control over what is yielded within that generator. Fibers may suspend deep within the call stack, perhaps within library code authored by another. Therefore it makes sense to move control of the scheduler used to the point of fiber suspension, rather than at fiber creation.

Additionally, using a fiber scheduler API enables a few features:

 * Suspension of the top-level (`{main}`): When the main fiber is suspended, execution continues into the fiber scheduler.
 * Nesting schedulers: A fiber may suspend into different fiber schedulers at various suspension points. Each scheduler will be started/suspended/resumed as needed. While a fully asynchronous app may want to ensure it does not use multiple fiber schedulers, a FPM application may find it acceptable to do so.
 * Elimination of boilerplate: Suspending at the top-level eliminates the need for an application to wrap code into a library-specific scheduler, allowing library code to suspend and resume as needed, without concern that the user used the appropriate boilerplate that may conflict with another libraries boilerplate.

#### What about performance?

Switching between fibers is lightweight, requiring changing the value of approximately 20 pointers, give or take, depending on platform. Switching context in the VM is similar to Generators. Since fibers exist within a single process thread, switching between fibers is significantly more performant than switching between processes/threads.

#### What platforms are supported?

Fibers are supported on nearly all modern CPU architectures, including x86, x86_64, 32- and 64-bit ARM, 32- and 64-bit PPC, MIPS, Windows, and older Posix platforms with ucontext. Support for C stack switching using assembly code is provided by [Boost](https://github.com/boostorg/context/tree/develop/src/asm), which has an [OSI-approved](https://opensource.org/licenses/BSL-1.0) [license](https://www.boost.org/LICENSE_1_0.txt) that allows components to be distributed directly with PHP.

`ext-fiber` is actively tested on [Travis](https://travis-ci.com/github/amphp/ext-fiber/builds) for x86_64 and 64-bit ARM, and on [AppVeyor](https://ci.appveyor.com/project/amphp/ext-fiber) for Windows.

#### How is a `FiberScheduler` implemented?

A `FiberScheduler` is like any other PHP class implementing an interface. The `run()` method may contain any necessary code to resume suspended fibers for the given application. Generally, a `FiberScheduler` would loop through available events, resuming fibers when an event occurs. The `ext-fiber` repo contains a [very simple implementation, `Loop`](https://github.com/amphp/ext-fiber/blob/7f838e1f067e32cc08cfe79e60feef95e0748b82/scripts/Loop.php) that is able to delay functions for a given number of milliseconds or until the scheduler is entered again. This simple implementation is used in the [`ext-fiber` phpt tests](https://github.com/amphp/ext-fiber/tree/7f838e1f067e32cc08cfe79e60feef95e0748b82/tests).

#### How does blocking code affect fibers

Blocking code (such as `file_get_contents()`) will continue to block the entire process, even if other fibers exist. Code must be written to use asynchonous I/O, an event loop, and fibers to see a performance and concurrency benefit. As mentioned in the introduction, several libraries already exist for asynchronous I/O and can take advantage of fibers to integrate with synchronous code while expanding the potential for concurrency in an application.

#### Why add this to PHP core?

Adding this capability directly in PHP core makes it widely available on any host providing PHP. Often users are not able to determine what extensions may be available in a particular hosting environment, are unsure of how to install extensions, or do not want to install 3rd-party extensions. With fibers in PHP core, any library author may use the feature without concerns for portability.

Futher, the extension currently uses the observer API to determine when fiber schedulers are run to completion, however the timing is not ideal, as it occurs *before* shutdown functions and destructors are executed. Adding the fibers to PHP core would allow the engine to finish executing fiber schedulers *after* registered shutdown functions are invoked.

#### Why not add an event loop and async/await API to core?

This RFC proposes only the bare minimum required to allow user code to implement full-stack coroutines or green-threads in PHP. There are several frameworks that implement their own event loop API, promises, and other asynchronous APIs. These APIs vary greatly and are opinionated, designed for a particular purpose, and their particular needs may not be able to be covered by a core API that is designed by only a few individuals.

It is the opinion of the author of this RFC that it is best to provide the bare minimum in core and allow user code to implement other components as they desire. If the community moves toward a single event loop API or a need emerges for an event loop in PHP core, this can be done in a future RFC. Providing a core event loop without core functionality using it (such as streams, file access, etc.) would be misleading and confusing for users. Deferring such functionality to user frameworks and providing only a minimum API in core keeps expectations in check.

This RFC does not preclude adding async/await and an event loop to core, see [Future Scope](#future-scope).

#### How does this proposal differ from prior Fiber proposals?

The prior [Fiber RFC](https://wiki.php.net/rfc/fiber) did not support context switching within internal calls (`array_map`, `preg_replace_callback`, etc.) or opcode handlers (`foreach`, `yield from`, etc.). This could result in a crash if a function using fibers was used in any user code called from C code or in extensions that override `zend_execute_ex` such as Xdebug.

The API proposed here also differs, allowing suspension of the main context.

## Backward Incompatible Changes

Declares `Fiber`, `FiberScheduler`, `FiberError`, `FiberExit`, `ReflectionFiber`, and `ReflectionFiberScheduler` in the root namespace. No other BC breaks.

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

These keywords can be created in user code using the proposed fiber API. [AMPHP v3](https://github.com/amphp/amp/tree/v3) (a work-in-progress) defines [`await()` and `async()`](https://github.com/amphp/amp/blob/0f2cf561427d3d9993bf2615ae21022d40200502/lib/functions.php#L6-L63) functions to await `Amp\Promise` instances and create new coroutines.

#### defer keyword 

Fibers may be used to implement a `defer` keyword that executes a statement within a new fiber when the current fiber is suspended or terminates. Such a keyword would also require an internal implementation of `FiberScheduler` and likely would be an addition after async/await keywords. This behavior differs from `defer` in Go, as PHP is already able to mimick such behavior with `finally` blocks.

This keyword also can be created in user code using the proposed fiber API, an example being [`defer()`](https://github.com/amphp/amp/blob/0f2cf561427d3d9993bf2615ae21022d40200502/lib/functions.php#L79-L98) in AMPHP v3.

## Proposed Voting Choices 

Merge implementation into core, 2/3 required.

## Patches and Tests 

Implementation and tests at [amphp/ext-fiber](https://github.com/amphp/ext-fiber).

[AMPHP v3](https://github.com/amphp/amp/tree/v3), a work-in-progress, uses `ext-fiber`. Nearly all libraries under the GitHub organization [amphp](https://github.com/amphp) have branches compatible with AMPHP v3. The branches are labeled as `vX`, where `X` is the current version + 1 (for example, the `v5` branch of [amphp/http-client](https://github.com/amphp/http-client/tree/v5)). See the `examples` directories in various libraries for samples of PHP code using fibers.

[React Fiber](https://github.com/trowski/react-fiber) uses `ext-fiber` to create coroutines and await any instance of `React\Promise\PromiseInterface` until it is resolved.

## Examples

This first example defines a very simple scheduler that is only able to defer function execution to a later time, which are then executed within the loop in `Scheduler::run()`. Nothing really useful is done here, but the example demonstrates the basics of how a fiber may be suspended and scheduled to be resumed in a scheduler (event loop) upon an event.

``` php
class Scheduler implements FiberScheduler
{
    private string $nextId = 'a';
    private array $callbacks = [];

    public function run(): void
    {
        while (!empty($this->callbacks)) {
            foreach ($this->callbacks as $id => $callback) {
                unset($this->callbacks[$id]);
                $callback();
            }
        }
    }

    public function defer(callable $callback): void
    {
        $this->callbacks[$this->nextId++] = $callback;
    }
}

$scheduler = new Scheduler;

// Suspend the main fiber, which will be resumed by the scheduler.
$value = Fiber::suspend(fn(Fiber $fiber) => $scheduler->defer(fn() => $fiber->resume("Test")), $scheduler);

echo "After resuming main fiber: ", $value, "\n"; // Output: After resuming main fiber: Test

// Suspend the main fiber again, but this time an exception will be thrown.
Fiber::suspend(fn(Fiber $fiber) => $scheduler->defer(fn() => $fiber->throw(new Exception("Test"))), $scheduler);
```

This example produces output equivalent to the following code:

``` php
$value = "Test";
echo "After resuming main fiber: ", $value, "\n";
throw new Exception("Test");
```

To be useful, rather than the scheduler immediately resuming the fiber, the scheduler should resume a fiber at a later time in response to an event. The next example demonstrates how a scheduler can resume a fiber in response to data becoming available on a socket.

----

The next example adds to the `FiberScheduler` the ability to poll a socket for incoming data, invoking a callback when data becomes available on the socket. This scheduler can now be used to resume a fiber *only* when data becomes available on a socket, avoiding a blocking read.

``` php
class Scheduler implements FiberScheduler
{
    private string $nextId = 'a';
    private array $deferCallbacks = [];
    private array $read = [];
    private array $streamCallbacks = [];

    public function run(): void
    {
        while (!empty($this->deferCallbacks) || !empty($this->read)) {
            foreach ($this->deferCallbacks as $id => $defer) {
                unset($this->deferCallbacks[$id]);
                $defer();
            }

            $this->select($this->read);
        }
    }

    private function select(array $read): void
    {
        $timeout = empty($this->deferCallbacks) ? null : 0;
        if (!stream_select($read, $write, $except, $timeout, $timeout)) {
            return;
        }

        foreach ($read as $id => $resource) {
            $callback = $this->streamCallbacks[$id];
            unset($this->read[$id], $this->streamCallbacks[$id]);
            $callback($resource);
        }
    }

    public function defer(callable $callback): void
    {
        $id = $this->nextId++;
        $this->deferCallbacks[$id] = $callback;
    }

    public function read($resource, callable $callback): void
    {
        $id = $this->nextId++;
        $this->read[$id] = $resource;
        $this->streamCallbacks[$id] = $callback;
    }
}

[$read, $write] = \stream_socket_pair(
    \stripos(PHP_OS, 'win') === 0 ? STREAM_PF_INET : STREAM_PF_UNIX,
    STREAM_SOCK_STREAM,
    STREAM_IPPROTO_IP
);

// Set streams to non-blocking mode.
\stream_set_blocking($read, false);
\stream_set_blocking($write, false);

$scheduler = new Scheduler;

// Read data in a separate fiber after checking if the stream is readable.
$fiber = Fiber::create(function () use ($scheduler, $read): void {
    echo "Waiting for data...\n";

    \Fiber::suspend(
        fn(Fiber $fiber) => $scheduler->read($read, fn() => $fiber->resume()),
        $scheduler
    );

    $data = \fread($read, 8192);

    echo "Received data: ", $data, "\n";
});

$scheduler->defer(fn() => $fiber->start());

// Suspend main fiber to enter the scheduler.
echo Fiber::suspend(
    fn(Fiber $fiber) => $scheduler->defer(fn() => $fiber->resume("Writing data...\n")),
    $scheduler
);

// Write data in main thread once it is resumed.
\fwrite($write, "Hello, world!");
```

This script will output the following:

```
Waiting for data...
Writing data...
Received data: Hello, world!
```

If this example were written in a similar order without fibers, the script would be unable to read from a socket before writing to it, as the call to `fread()` would block until data was available.

----

The next example below uses [`Loop`](https://github.com/amphp/ext-fiber/blob/7f838e1f067e32cc08cfe79e60feef95e0748b82/scripts/Loop.php), a simple implemenation of `FiberScheduler`, yet more complex than that in the above examples, to delay execution of a function for 1000 milliseconds. When the fiber is suspended with `Fiber::suspend()`, resumption of the fiber is scheduled with  `Loop->delay()`, which invokes the callback after the given number of milliseconds.

``` php
$loop = new Loop;

$value = Fiber::suspend(function (Fiber $fiber) use ($loop): void {
    $loop->delay(1000, fn() => $fiber->resume(1));
}, $loop);

var_dump($value); // int(1)
```

This example can be expanded to create multiple fibers, each with it's own delay before resuming.

``` php
$loop = new Loop;

// Create three new fibers and run them in the FiberScheduler.
$fiber = Fiber::create(function () use ($loop): void {
    $value = Fiber::suspend(function (Fiber $fiber) use ($loop): void {
        $loop->delay(1500, fn() => $fiber->resume(1));
    }, $loop);
    var_dump($value);
});
$loop->defer(fn() => $fiber->start());

$fiber = Fiber::create(function () use ($loop): void {
    $value = Fiber::suspend(function (Fiber $fiber) use ($loop): void {
        $loop->delay(1000, fn() => $fiber->resume(2));
    }, $loop);
    var_dump($value);
});
$loop->defer(fn() => $fiber->start());

$fiber = Fiber::create(function () use ($loop): void {
    $value = Fiber::suspend(function (Fiber $fiber) use ($loop): void {
        $loop->delay(2000, fn() => $fiber->resume(3));
    }, $loop);
    var_dump($value);
});
$loop->defer(fn() => $fiber->start());

// Suspend the main thread to enter the FiberScheduler.
$value = Fiber::suspend(function (Fiber $fiber) use ($loop): void {
    $loop->delay(500, fn() => $fiber->resume(4));
}, $loop);
var_dump($value);
```

The above code will output the following:

```
int(4)
int(2)
int(1)
int(3)
```

Total execution time for the script is 2 seconds (2000ms) as this is the longest delay (sleep) defined. A similar synchronous script would take 5 seconds to execute as each delay would be in series rather than concurrent.

While a contrived example, imagine if each of the fibers was awaiting data on a network socket or the result of a database query. Combining this with the ability to simultaneously run and suspend many fibers allows a single PHP process to concurrently await many events.

----

The next few examples use the async framework [AMPHP v3](https://github.com/amphp/amp/tree/v3) mentioned in [Patches and Tests](#patches-and-tests) to demonstrate how fibers may be used by frameworks to create asynchronous code that is written like synchronous code.

AMPHP v3 uses an [event loop interface](https://github.com/amphp/amp/blob/0f2cf561427d3d9993bf2615ae21022d40200502/lib/Loop/Driver.php) that extends `FiberScheduler` together with a variety of functions and a placeholder object ([`Promise`](https://github.com/amphp/amp/blob/0f2cf561427d3d9993bf2615ae21022d40200502/lib/Promise.php)) to build on top of the underlying fiber API to create its own opinionated API to create green-threads (coroutines) to execute code concurrently. Users of AMPHP v3 do not use the Fiber API directly, the framework handles suspending and creating fibers as necessary. Other frameworks may choose to approach creating green-threads and placeholders differently.

The [`defer(callable $callback, mixed ...$args)`](https://github.com/amphp/amp/blob/0f2cf561427d3d9993bf2615ae21022d40200502/lib/functions.php#L79-L98) function creates a new fiber that is executed when the current fiber suspends or terminates. [`delay(int $milliseconds)`](https://github.com/amphp/amp/blob/v3/lib/functions.php#L216-L227) suspends the current fiber until the given number of milliseconds has elasped.

This example is similar to the example above which creating mutiple fibers with different delays, but the underlying Fiber API is abstracted away into an API specific to the Amp framework. Note again this code is specific to AMPHP v3 and not part of this RFC, other frameworks may choose to implement this behavior in a different way.

``` php
use function Amp\defer;
use function Amp\delay;

defer(function (): void {
    delay(1500);
    var_dump(1);
});

defer(function (): void {
    delay(1000);
    var_dump(2);
});

defer(function (): void {
    delay(2000);
    var_dump(3);
});

delay(500);
var_dump(4);
```

----

The next example again uses AMPHP v3 to demonstrate how the `FiberScheduler` fiber continues executing while the main thread is suspended. The [`await(Promise $promise)`](https://github.com/amphp/amp/blob/v3/lib/functions.php#L6-L34) function suspends a fiber until the given promise is resolved and the [`async(callable $callback, mixed ...$args)`](https://github.com/amphp/amp/blob/v3/lib/functions.php#L36-L63) function creates a new fiber, returning a promise that is resolved when the fiber completes, allowing multiple fibers to be executed concurrently.

``` php
use function Amp\async;
use function Amp\await;
use function Amp\defer;
use function Amp\delay;

// Note that the function declares int as a return type, not Promise or Generator, but executes as a coroutine.
function asyncTask(int $id): int {
    // Nothing useful is done here, but rather acts as a substitute for async I/O.
    delay(1000); // Pauses the fiber this function executes within for 1 second.
    return $id;
}

$running = true;
defer(function () use (&$running): void {
    // This loop is to show how this fiber is not blocked by other fibers.
    while ($running) {
        delay(100);
        echo ".\n";
    }
});

// Invoking $callback returns an int, but is executed asynchronously.
$result = asyncTask(1); // Call a subroutine within this green thread, taking 1 second to return.
var_dump($result);

// Simultaneously runs two new green threads, await their resolution in this green thread.
$result = await([  // Executed simultaneously, only 1 second will elapse during this await.
    async('asyncTask', 2),
    async('asyncTask', 3),
]);
var_dump($result); // Executed after 2 seconds.

$result = asyncTask(4); // Call takes 1 second to return.
var_dump($result);

// array_map() takes 2 seconds to execute as the calls are not concurrent, but this shows that fibers are
// supported by internal callbacks.
$result = array_map('asyncTask', [5, 6]);
var_dump($result);

$running = false; // Stop the loop in the fiber created with defer() above.
```

----

Since fibers can be paused during calls within the PHP VM, fibers can also be used to create asynchronous iterators and generators. The example below uses AMPHP v3 to suspend a fiber within a generator, awaiting resolution of a [`Delayed`](https://github.com/amphp/amp/blob/0f2cf561427d3d9993bf2615ae21022d40200502/lib/Delayed.php), a promise-like object that resolves itself with the second argument after the number of milliseconds given as the first argument. When iterating over the generator, the `foreach` loop will suspend while waiting for another value to be yielded from the generator.

``` php
use Amp\Delayed;
use function Amp\await;

function generator(): Generator {
    yield await(new Delayed(500, 1));
    yield await(new Delayed(1500, 2));
    yield await(new Delayed(1000, 3));
    yield await(new Delayed(2000, 4));
    yield 5;
    yield 6;
    yield 7;
    yield await(new Delayed(2000, 8));
    yield 9;
    yield await(new Delayed(1000, 10));
}

$generator = generator();

foreach ($generator as $value) {
    printf("Generator yielded %d\n", $value);
}
```

----

The example below shows how [ReactPHP](https://github.com/reactphp) might use fibers to define an `await()` function using their `PromiseInterface` and `LoopInterface`. (Note this example assumes `LoopInterface` would extend `FiberScheduler`, which it already implements without modification.)

``` php
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

function await(PromiseInterface $promise, LoopInterface $loop): mixed
{
    $enqueue = fn(Fiber $fiber) => $promise->done(
        fn(mixed $value) => $loop->futureTick(fn() => $fiber->resume($value)),
        fn(Throwable $reason) => $loop->futureTick(fn() => $fiber->throw($reason)
    ));

    return Fiber::suspend($enqueue, $loop);
}
```

A demonstration of integrating ReactPHP with fibers has been implemented in [`trowski/react-fiber`](https://github.com/trowski/react-fiber) for the current stable versions of `react/event-loop` and `react/promise`.

----

The final example uses the `cURL` extension to create a fiber scheduler based on `curl_multi_exec()` to perform multiple HTTP requests concurrently. When a new fiber is started, `Scheduler->async()` returns a `Promise`, a placeholder to represent the eventual result of the new fiber, which may be awaited later using `Scheduler->await()`.

``` php
class Promise
{
    /** @var Fiber[] */
    private array $fibers = [];
    private Scheduler $scheduler;
    private bool $resolved = false;
    private ?\Throwable $error = null;
    private mixed $result;

    public function __construct(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function await(): mixed
    {
        if (!$this->resolved) {
            return \Fiber::suspend($this, $this->scheduler);
        }

        if ($this->error) {
            throw $this->error;
        }

        return $this->result;
    }

    public function __invoke(Fiber $fiber): void
    {
        if ($this->resolved) {
            if ($this->error !== null) {
                $this->scheduler->defer(fn() => $fiber->throw($this->error));
            } else {
                $this->scheduler->defer(fn() => $fiber->resume($this->result));
            }

            return;
        }

        $this->fibers[] = $fiber;
    }

    public function resolve(mixed $value = null): void
    {
        if ($this->resolved) {
            throw new Error("Promise already resolved");
        }

        $this->result = $value;
        $this->continue();
    }

    public function fail(Throwable $error): void
    {
        if ($this->resolved) {
            throw new Error("Promise already resolved");
        }

        $this->error = $error;
        $this->continue();
    }

    private function continue(): void
    {
        $this->resolved = true;

        $fibers = $this->fibers;
        $this->fibers = [];

        foreach ($fibers as $fiber) {
            ($this)($fiber);
        }
    }
}

class Scheduler implements FiberScheduler
{
    /** @var resource */
    private $curl;
    /** @var callable[] */
    private array $defers = [];
    /** @var Fiber[] */
    private array $fibers = [];

    public function __construct()
    {
        $this->curl = curl_multi_init();
    }

    public function __destruct()
    {
        curl_multi_close($this->curl);
    }

    public function fetch(string $url): string
    {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        curl_multi_add_handle($this->curl, $curl);

        Fiber::suspend(function (Fiber $fiber) use ($curl) {
            $this->fibers[(int) $curl] = $fiber;
        }, $this);

        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status !== 200) {
            throw new Exception(\sprintf('Request to %s failed with status code %d', $url, $status));
        }

        $body = substr(trim(curl_multi_getcontent($curl)), 0, 255);

        curl_close($curl);

        return $body;
    }

    public function defer(callable $callable): void
    {
        $this->defers[] = $callable;
    }

    public function async(callable $callable): Promise
    {
        $promise = new Promise($this);

        $fiber = Fiber::create(function () use ($promise, $callable) {
            try {
                $promise->resolve($callable());
            } catch (\Throwable $e) {
                $promise->fail($e);
            }
        });

        $this->defer(fn() => $fiber->start());

        return $promise;
    }

    public function run(): void
    {
        do {
            do {
                $defers = $this->defers;
                $this->defers = [];

                foreach ($defers as $callable) {
                    $callable();
                }

                $status = curl_multi_exec($this->curl, $active);
                if ($active) {
                    $select = curl_multi_select($this->curl);
                    if ($select > 0) {
                        $this->processQueue();
                    }
                }
            } while ($active && $status === CURLM_OK);

            $this->processQueue();
        } while ($this->defers);
    }

    private function processQueue(): void
    {
        while ($info = curl_multi_info_read($this->curl)) {
            if ($info['msg'] !== CURLMSG_DONE) {
                continue;
            }

            $fiber = $this->fibers[(int) $info['handle']];
            $fiber->resume();
        }
    }
}

function await(Promise ...$promises): array
{
    return array_map(fn($promise) => $promise->await(), $promises);
}

$urls = array_fill(0, $argv[1] ?? 10, 'https://amphp.org/');

$scheduler = new Scheduler;

$promises = [];
foreach ($urls as $url) {
    $promises[] = $scheduler->async(fn() => $scheduler->fetch($url));
}

print 'Starting to make ' . count($promises) . ' requests...' . PHP_EOL;

$start = hrtime(true);

$responses = await(...$promises);

// var_dump($responses);

print ((hrtime(true) - $start) / 1_000_000) . 'ms' . PHP_EOL;
```

## References 
  * [Boost C++ fibers](https://www.boost.org/doc/libs/1_67_0/libs/fiber/doc/html/index.html)
  * [Ruby Fibers](https://ruby-doc.org/core-2.5.0/Fiber.html)
  * [Lua Fibers](https://wingolog.org/archives/2018/05/16/lightweight-concurrency-in-lua)
  * [Project Loom for Java](https://cr.openjdk.java.net/~rpressler/loom/Loom-Proposal.html)
