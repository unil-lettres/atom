#!/usr/bin/env python3
"""Summarize AtoM nginx access logs for traffic-management reporting."""

from __future__ import annotations

import argparse
import gzip
import json
import re
from collections import Counter, defaultdict
from pathlib import Path


MONTH = {
    "Jan": "01",
    "Feb": "02",
    "Mar": "03",
    "Apr": "04",
    "May": "05",
    "Jun": "06",
    "Jul": "07",
    "Aug": "08",
    "Sep": "09",
    "Oct": "10",
    "Nov": "11",
    "Dec": "12",
}

LINE_RE = re.compile(
    r'^(?P<ip>\S+) \S+ \S+ \[(?P<dt>[^:]+):(?P<time>[^ ]+) [^\]]+\] '
    r'"(?P<method>\S+) (?P<uri>[^" ]*) (?P<proto>[^"]*)" '
    r'(?P<status>\d{3}) (?P<bytes>\S+) "(?P<ref>[^"]*)" "(?P<ua>[^"]*)"'
)

BOT_RE = re.compile(
    r"bot|crawler|spider|slurp|bingpreview|googleother|bytespider|ahrefs|"
    r"semrush|mj12|dotbot|petalbot|dataforseo|blexbot",
    re.I,
)

KNOWN_BOT_RE = re.compile(
    r"Googlebot|bingbot|BingPreview|GPTBot|ClaudeBot|Amazonbot|AhrefsBot|"
    r"SemrushBot|MJ12bot|DotBot|DataForSeoBot|BLEXBot|Bytespider|PetalBot|"
    r"GoogleOther",
    re.I,
)


def day_from_nginx_date(value: str) -> str:
    day, month, year = value.split("/")
    return f"{year}-{MONTH[month]}-{int(day):02d}"


def route_for(uri: str) -> str:
    low = uri.lower()

    if "informationobject/fullwidthtreeview" in low:
        return "tree"

    if "informationobject/browse" in low:
        return "browse"

    if ";oai" in low or ("verb=" in low and "metadataprefix" in low):
        return "oai"

    if "sf_format=xml" in low or "/downloads/exports/ead/" in low or low.endswith(".ead.xml"):
        return "xml_ead"

    if "/search" in low:
        return "search"

    if low.startswith("/index.php"):
        return "other_index"

    if re.search(r"\.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|map)(\?|$)", low):
        return "static"

    return "other"


def is_unil_ip(ip: str) -> bool:
    return ip.startswith("130.223.") or ip.startswith("2001:620:610:")


def open_log(path: Path):
    if path.suffix == ".gz":
        return gzip.open(path, "rt", errors="replace")

    return path.open("rt", errors="replace")


def summarize(paths: list[Path]) -> dict:
    daily: defaultdict[str, Counter] = defaultdict(Counter)
    top_429_ip: Counter = Counter()
    top_429_ua: Counter = Counter()
    top_429_uri: Counter = Counter()
    top_browse_ip: Counter = Counter()
    files_used: list[str] = []
    total_lines = 0
    parsed_lines = 0
    first_day = None
    last_day = None

    for path in sorted(paths, key=lambda item: str(item)):
        used = False

        with open_log(path) as handle:
            for line in handle:
                total_lines += 1
                match = LINE_RE.match(line)

                if not match:
                    continue

                parsed_lines += 1
                used = True
                data = match.groupdict()
                day = day_from_nginx_date(data["dt"])
                status = int(data["status"])
                uri = data["uri"]
                ua = data["ua"]
                ip = data["ip"]
                route = route_for(uri)
                is_bot = bool(BOT_RE.search(ua))
                is_known_bot = bool(KNOWN_BOT_RE.search(ua))
                is_unil = is_unil_ip(ip)

                first_day = min(first_day, day) if first_day else day
                last_day = max(last_day, day) if last_day else day

                counter = daily[day]
                counter["requests"] += 1
                counter[f"{status // 100}xx"] += 1
                counter[f"status_{status}"] += 1
                counter[f"route_{route}"] += 1
                counter["bot_like"] += int(is_bot)
                counter["known_bot"] += int(is_known_bot)
                counter["unil"] += int(is_unil)
                counter["public"] += int(not is_unil)

                if status == 429:
                    counter["429"] += 1
                    counter[f"429_{route}"] += 1
                    counter["429_bot_like"] += int(is_bot)
                    counter["429_unil"] += int(is_unil)
                    counter["429_public"] += int(not is_unil)
                    top_429_ip[ip] += 1
                    top_429_ua[ua[:160]] += 1
                    top_429_uri[uri[:180]] += 1

                if route == "browse":
                    top_browse_ip[ip] += 1

        if used:
            files_used.append(str(path))

    periods = {
        "may06_11_before_ead_work": ("2026-05-06", "2026-05-11"),
        "may12_14_deploy_refresh_period": ("2026-05-12", "2026-05-14"),
        "may15_19_quieter_period": ("2026-05-15", "2026-05-19"),
    }
    period_stats = {}

    for name, (start, end) in periods.items():
        aggregate: Counter = Counter()

        for day, counter in daily.items():
            if start <= day <= end:
                aggregate.update(counter)

        period_stats[name] = dict(aggregate)

    return {
        "files_used": files_used,
        "first_day": first_day,
        "last_day": last_day,
        "total_lines": total_lines,
        "parsed_lines": parsed_lines,
        "daily": {day: dict(daily[day]) for day in sorted(daily)},
        "periods": period_stats,
        "top_429_ip": top_429_ip.most_common(20),
        "top_429_ua": top_429_ua.most_common(12),
        "top_429_uri": top_429_uri.most_common(20),
        "top_browse_ip": top_browse_ip.most_common(20),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("paths", nargs="+", type=Path)
    args = parser.parse_args()

    print(json.dumps(summarize(args.paths), indent=2, sort_keys=True))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
