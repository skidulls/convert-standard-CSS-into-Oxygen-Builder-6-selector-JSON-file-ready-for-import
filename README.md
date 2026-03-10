This plugin is a WordPress admin tool that converts standard CSS into Oxygen builder 6 selector JSON format, allowing you to import CSS classes directly into the builder as a json file.


1. What it can do

The tool currently supports:

Converting standard CSS classes into Oxygen classes

Importing classes directly through the Oxygen/Breakdance selector importer

Handling responsive styles and mapping them to Oxygen breakpoints:

base

tablet landscape

tablet portrait

phone landscape

phone portrait

Converting nested selectors into Oxygen child selectors

:hover

::before

::after

descendant selectors like .class span

Converting many CSS properties into the correct Oxygen property structure

Falling back to custom CSS when a property cannot be mapped to a builder control

2. What it cannot do (yet)

Currently the tool does not fully support:

CSS animations and keyframes

Advanced combinators and very complex selectors

Full coverage of all CSS properties used by Oxygen

Automatic conversion of external frameworks (like Tailwind or Bootstrap) without some manual cleanup

Parsing of every possible media query format
