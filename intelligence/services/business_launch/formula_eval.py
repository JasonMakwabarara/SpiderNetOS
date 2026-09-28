"""Tiny spreadsheet-formula evaluator for the finance model's workbook.

The finance model writes live formulas into ``finance/model.xlsx``. This module
evaluates exactly the grammar the model's layout
(``templates/finance-model.yaml``) uses, so the workbook can be checked
cell-by-cell against the Python projection — offline, without openpyxl, a
spreadsheet engine or the ``formulas`` package.

Supported
    numbers, "strings", TRUE/FALSE
    cell refs (A1, $B$3), sheet refs (Revenue!D2, 'P&L'!F2), ranges (B2:E2)
    + - * / ^, unary + and -, & (text join)
    = <> < > <= >=
    SUM, MIN, MAX, IF, ROUND, ABS

A workbook is ``{sheet_name: [[cell, ...], ...]}`` where a cell is a Python
value or a string starting with ``=``. Rows and columns are 1-based in
references, 0-based in the lists. Empty cells read as 0 in arithmetic and are
skipped by SUM/MIN/MAX. Circular references raise ``FormulaError``.
"""

from __future__ import annotations

import math
import re
from dataclasses import dataclass
from typing import Any, Callable, Iterable

__all__ = [
    "FormulaError",
    "Workbook",
    "column_index",
    "column_letter",
    "evaluate_workbook",
]


class FormulaError(ValueError):
    """Raised for unparseable formulas, bad references or cycles."""


_TOKEN_RE = re.compile(
    r"""
    (?P<ws>\s+)
  | (?P<number>\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)
  | (?P<string>"(?:[^"]|"")*")
  | (?P<qsheet>'(?:[^']|'')+'!)
  | (?P<sheet>[A-Za-z_][A-Za-z0-9_.]*!)
  | (?P<cell>\$?[A-Za-z]{1,3}\$?\d+(?![A-Za-z0-9_(]))
  | (?P<ident>[A-Za-z_][A-Za-z0-9_.]*)
  | (?P<op><>|<=|>=|[-+*/^&=<>(),:])
    """,
    re.VERBOSE,
)

_CELL_RE = re.compile(r"^\$?([A-Za-z]{1,3})\$?(\d+)$")


def column_index(letters: str) -> int:
    """``A`` → 1, ``Z`` → 26, ``AA`` → 27."""
    index = 0
    for char in letters.upper():
        index = index * 26 + (ord(char) - 64)
    return index


def column_letter(index: int) -> str:
    """1 → ``A``, 27 → ``AA``."""
    if index < 1:
        raise FormulaError(f"Invalid column index: {index}")
    letters = ""
    while index:
        index, rem = divmod(index - 1, 26)
        letters = chr(65 + rem) + letters
    return letters


@dataclass(frozen=True)
class _Token:
    kind: str
    value: str


@dataclass(frozen=True)
class _Ref:
    sheet: str | None
    col: int
    row: int


@dataclass(frozen=True)
class _Range:
    sheet: str | None
    start: _Ref
    end: _Ref


def _tokenize(formula: str) -> list[_Token]:
    tokens: list[_Token] = []
    pos = 0
    while pos < len(formula):
        match = _TOKEN_RE.match(formula, pos)
        if not match:
            raise FormulaError(f"Unexpected character {formula[pos]!r} in {formula!r}")
        pos = match.end()
        kind = match.lastgroup or ""
        if kind == "ws":
            continue
        tokens.append(_Token(kind, match.group(kind)))
    return tokens


class _Parser:
    """Recursive-descent parser producing a small AST of tuples."""

    def __init__(self, formula: str):
        self.formula = formula
        self.tokens = _tokenize(formula)
        self.pos = 0

    def parse(self) -> Any:
        node = self._comparison()
        if self.pos != len(self.tokens):
            raise FormulaError(f"Trailing tokens in {self.formula!r}")
        return node

    # -- helpers -----------------------------------------------------------
    def _peek(self) -> _Token | None:
        return self.tokens[self.pos] if self.pos < len(self.tokens) else None

    def _take(self) -> _Token:
        token = self._peek()
        if token is None:
            raise FormulaError(f"Unexpected end of formula {self.formula!r}")
        self.pos += 1
        return token

    def _accept_op(self, *ops: str) -> str | None:
        token = self._peek()
        if token is not None and token.kind == "op" and token.value in ops:
            self.pos += 1
            return token.value
        return None

    def _expect_op(self, op: str) -> None:
        if self._accept_op(op) is None:
            raise FormulaError(f"Expected {op!r} in {self.formula!r}")

    # -- grammar -----------------------------------------------------------
    def _comparison(self) -> Any:
        left = self._concat()
        op = self._accept_op("=", "<>", "<", ">", "<=", ">=")
        if op is None:
            return left
        return ("cmp", op, left, self._concat())

    def _concat(self) -> Any:
        node = self._additive()
        while self._accept_op("&"):
            node = ("concat", node, self._additive())
        return node

    def _additive(self) -> Any:
        node = self._term()
        while True:
            op = self._accept_op("+", "-")
            if op is None:
                return node
            node = ("bin", op, node, self._term())

    def _term(self) -> Any:
        node = self._power()
        while True:
            op = self._accept_op("*", "/")
            if op is None:
                return node
            node = ("bin", op, node, self._power())

    def _power(self) -> Any:
        # Excel: negation binds tighter than ^ and ^ is left-associative.
        node = self._unary()
        while self._accept_op("^"):
            node = ("bin", "^", node, self._unary())
        return node

    def _unary(self) -> Any:
        op = self._accept_op("-", "+")
        if op is not None:
            return ("neg", self._unary()) if op == "-" else self._unary()
        return self._primary()

    def _primary(self) -> Any:
        token = self._take()
        if token.kind == "number":
            return ("num", float(token.value))
        if token.kind == "string":
            return ("str", token.value[1:-1].replace('""', '"'))
        if token.kind == "op" and token.value == "(":
            node = self._comparison()
            self._expect_op(")")
            return node
        if token.kind in ("sheet", "qsheet"):
            sheet = token.value[:-1]
            if token.kind == "qsheet":
                sheet = sheet[1:-1].replace("''", "'")
            cell = self._take()
            if cell.kind != "cell":
                raise FormulaError(f"Expected a cell after {token.value!r} in {self.formula!r}")
            return self._ref_or_range(sheet, cell.value)
        if token.kind == "cell":
            return self._ref_or_range(None, token.value)
        if token.kind == "ident":
            name = token.value.upper()
            if name in ("TRUE", "FALSE") and not self._accept_op("("):
                return ("bool", name == "TRUE")
            if self._peek() is None or self._peek().value != "(":  # type: ignore[union-attr]
                raise FormulaError(f"Unknown name {token.value!r} in {self.formula!r}")
            self._expect_op("(")
            args: list[Any] = []
            if not self._accept_op(")"):
                while True:
                    args.append(self._comparison())
                    if self._accept_op(")"):
                        break
                    self._expect_op(",")
            return ("call", name, args)
        raise FormulaError(f"Unexpected token {token.value!r} in {self.formula!r}")

    def _ref_or_range(self, sheet: str | None, cell: str) -> Any:
        start = _parse_cell(sheet, cell)
        if self._accept_op(":"):
            end_token = self._take()
            if end_token.kind != "cell":
                raise FormulaError(f"Bad range end in {self.formula!r}")
            return ("range", _Range(sheet, start, _parse_cell(sheet, end_token.value)))
        return ("ref", start)


def _parse_cell(sheet: str | None, text: str) -> _Ref:
    match = _CELL_RE.match(text)
    if not match:
        raise FormulaError(f"Bad cell reference {text!r}")
    return _Ref(sheet, column_index(match.group(1)), int(match.group(2)))


def _num(value: Any) -> float:
    if value is None or value == "":
        return 0.0
    if isinstance(value, bool):
        return 1.0 if value else 0.0
    if isinstance(value, (int, float)):
        return float(value)
    try:
        return float(value)
    except (TypeError, ValueError) as exc:
        raise FormulaError(f"Not a number: {value!r}") from exc


class Workbook:
    """Lazily evaluates every formula cell of a workbook dictionary."""

    def __init__(self, sheets: dict[str, list[list[Any]]]):
        self.sheets = sheets
        self._cache: dict[tuple[str, int, int], Any] = {}
        self._stack: set[tuple[str, int, int]] = set()
        self._ast: dict[str, Any] = {}

    def raw(self, sheet: str, col: int, row: int) -> Any:
        rows = self.sheets.get(sheet)
        if rows is None:
            raise FormulaError(f"Unknown sheet {sheet!r}")
        if row < 1 or row > len(rows):
            return None
        cells = rows[row - 1]
        if col < 1 or col > len(cells):
            return None
        return cells[col - 1]

    def value(self, sheet: str, address: str) -> Any:
        ref = _parse_cell(sheet, address)
        return self._value(sheet, ref.col, ref.row)

    def _value(self, sheet: str, col: int, row: int) -> Any:
        key = (sheet, col, row)
        if key in self._cache:
            return self._cache[key]
        raw = self.raw(sheet, col, row)
        if isinstance(raw, str) and raw.startswith("="):
            if key in self._stack:
                raise FormulaError(f"Circular reference at {sheet}!{column_letter(col)}{row}")
            self._stack.add(key)
            try:
                ast = self._ast.get(raw)
                if ast is None:
                    ast = _Parser(raw[1:]).parse()
                    self._ast[raw] = ast
                result = self._eval(ast, sheet)
            finally:
                self._stack.discard(key)
        else:
            result = raw
        self._cache[key] = result
        return result

    def _range_values(self, rng: _Range, current_sheet: str) -> Iterable[Any]:
        sheet = rng.sheet or current_sheet
        for row in range(min(rng.start.row, rng.end.row), max(rng.start.row, rng.end.row) + 1):
            for col in range(min(rng.start.col, rng.end.col), max(rng.start.col, rng.end.col) + 1):
                yield self._value(sheet, col, row)

    def _args_numbers(self, args: list[Any], sheet: str) -> list[float]:
        numbers: list[float] = []
        for arg in args:
            if arg[0] == "range":
                for value in self._range_values(arg[1], sheet):
                    if isinstance(value, bool) or value is None or value == "":
                        continue
                    if isinstance(value, (int, float)):
                        numbers.append(float(value))
            else:
                numbers.append(_num(self._eval(arg, sheet)))
        return numbers

    def _eval(self, node: Any, sheet: str) -> Any:
        kind = node[0]
        if kind in ("num", "str", "bool"):
            return node[1]
        if kind == "ref":
            ref: _Ref = node[1]
            return self._value(ref.sheet or sheet, ref.col, ref.row)
        if kind == "range":
            raise FormulaError("A range can only be used inside a function")
        if kind == "neg":
            return -_num(self._eval(node[1], sheet))
        if kind == "concat":
            return f"{self._text(self._eval(node[1], sheet))}{self._text(self._eval(node[2], sheet))}"
        if kind == "bin":
            op, left, right = node[1], _num(self._eval(node[2], sheet)), _num(self._eval(node[3], sheet))
            if op == "+":
                return left + right
            if op == "-":
                return left - right
            if op == "*":
                return left * right
            if op == "/":
                if right == 0:
                    raise FormulaError("#DIV/0!")
                return left / right
            return math.pow(left, right)
        if kind == "cmp":
            return _compare(node[1], self._eval(node[2], sheet), self._eval(node[3], sheet))
        if kind == "call":
            return self._call(node[1], node[2], sheet)
        raise FormulaError(f"Unknown node {kind!r}")

    def _call(self, name: str, args: list[Any], sheet: str) -> Any:
        functions: dict[str, Callable[[], Any]] = {
            "SUM": lambda: math.fsum(self._args_numbers(args, sheet)),
            "MIN": lambda: min(self._args_numbers(args, sheet), default=0.0),
            "MAX": lambda: max(self._args_numbers(args, sheet), default=0.0),
            "ABS": lambda: abs(_num(self._eval(args[0], sheet))),
            "ROUND": lambda: _excel_round(_num(self._eval(args[0], sheet)), int(_num(self._eval(args[1], sheet))) if len(args) > 1 else 0),
            "IF": lambda: self._if(args, sheet),
        }
        if name not in functions:
            raise FormulaError(f"Unsupported function {name}")
        return functions[name]()

    def _if(self, args: list[Any], sheet: str) -> Any:
        if len(args) < 2:
            raise FormulaError("IF needs at least two arguments")
        condition = self._eval(args[0], sheet)
        truthy = condition if isinstance(condition, bool) else _num(condition) != 0
        if truthy:
            return self._eval(args[1], sheet)
        return self._eval(args[2], sheet) if len(args) > 2 else False

    @staticmethod
    def _text(value: Any) -> str:
        if value is None:
            return ""
        if isinstance(value, float) and value.is_integer():
            return str(int(value))
        return str(value)


def _compare(op: str, left: Any, right: Any) -> bool:
    if isinstance(left, str) or isinstance(right, str):
        a, b = str(left).lower(), str(right).lower()
    else:
        a, b = _num(left), _num(right)
    return {
        "=": a == b,
        "<>": a != b,
        "<": a < b,
        ">": a > b,
        "<=": a <= b,
        ">=": a >= b,
    }[op]


def _excel_round(value: float, digits: int) -> float:
    factor = 10.0 ** digits
    return math.copysign(math.floor(abs(value) * factor + 0.5) / factor, value)


def evaluate_workbook(sheets: dict[str, list[list[Any]]]) -> dict[str, list[list[Any]]]:
    """Every cell of every sheet with formulas replaced by their values."""
    book = Workbook(sheets)
    out: dict[str, list[list[Any]]] = {}
    for name, rows in sheets.items():
        out[name] = [
            [book._value(name, col, row) for col in range(1, len(cells) + 1)]  # noqa: SLF001
            for row, cells in enumerate(rows, start=1)
        ]
    return out
