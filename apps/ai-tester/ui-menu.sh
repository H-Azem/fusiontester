#!/bin/sh
# Turn a uiautomator dump into the short list of things a person could act on.
#
# The raw dump is thousands of nodes on ONE line and reading it costs a whole step
# budget. This prints one line per actionable node — id, text and the tap point
# already worked out of its bounds — so the lane taps what it sees instead of
# parsing XML.
set -eu

file="${1:?usage: ui-menu <dump.xml>}"

sed 's/<node /\n/g' "$file" | awk '
{
  id = attr($0, "resource-id")
  text = attr($0, "text")
  desc = attr($0, "content-desc")
  click = attr($0, "clickable")
  bounds = attr($0, "bounds")

  if (click != "true" && id == "" && text == "" && desc == "") next

  label = (text != "" ? text : desc)
  printf "%s | id=%s | tap=%s\n", (label == "" ? "?" : label), id, centerOf(bounds)
}
function attr(s, name,   re, r) {
  re = name "=\"[^\"]*\""
  if (match(s, re) == 0) return ""
  r = substr(s, RSTART, RLENGTH)
  sub(name "=\"", "", r)
  sub("\"$", "", r)
  return r
}
function centerOf(b,   p, xy) {
  if (match(b, /\[[0-9]+,[0-9]+\]\[[0-9]+,[0-9]+\]/) == 0) return "-"
  p = substr(b, RSTART, RLENGTH)
  split(p, xy, /[^0-9]+/)
  # split on a leading non-digit leaves an empty first field
  return int((xy[2] + xy[4]) / 2) "," int((xy[3] + xy[5]) / 2)
}
'
