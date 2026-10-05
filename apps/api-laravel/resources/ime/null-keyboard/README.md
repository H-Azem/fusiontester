# Null Keyboard

The test device is a container, and the soft keyboard is the single most disruptive
thing on it: it covers the bottom of the screen, pushes the app's layout around, and
hides the element a flow is about to assert on. Maestro injects text itself and never
needs a keyboard, so the device is given one that draws nothing.

A real input method is left **enabled** — Android restores its default IME when no IME
is enabled at all, which is why simply disabling it does not hold.

## What is here

`base.apk`, `split_config.xhdpi.apk` and `split_config.en.apk`, taken from
Null Keyboard 1.1.6.582 (`com.nilac.nullkeyboard`), an `.apks` bundle. The device is
320 dpi and English, so those are the base plus the two splits it needs;
`install-multiple` is given exactly those three. `prepareDevice()` installs them only
when the package is missing, and selects the IME on every run.

## Worth knowing

The APK is the publisher's Play build, fetched from a third-party mirror, and it asks
for `INTERNET`, `AD_ID` and `BILLING` permissions that an input method that draws
nothing has no use for. It runs on a disposable test device holding fake data, and it
is an input method, so it can see everything typed into it. Replacing it with the
open-source `io.github.visnkmr.nokeyboard` is a drop-in swap — same two constants in
`ExecuteRunAction` and the same three files.
