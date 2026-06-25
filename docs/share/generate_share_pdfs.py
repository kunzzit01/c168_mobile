# -*- coding: utf-8 -*-
"""Generate Friday sharing PDFs (A oral script + C methodology outline)."""
from pathlib import Path

from fpdf import FPDF

FONT = Path(r"C:\Windows\Fonts\msyh.ttc")
OUT_DIR = Path(__file__).resolve().parent


class SharePDF(FPDF):
    def __init__(self, title: str):
        super().__init__()
        self.doc_title = title
        self.add_font("msyh", "", str(FONT))
        self.set_auto_page_break(auto=True, margin=18)

    def header(self):
        self.set_font("msyh", "", 9)
        self.set_text_color(120, 120, 120)
        self.cell(0, 8, self.doc_title, align="R", new_x="LMARGIN", new_y="NEXT")
        self.set_text_color(0, 0, 0)
        self.ln(2)

    def footer(self):
        self.set_y(-12)
        self.set_font("msyh", "", 9)
        self.set_text_color(120, 120, 120)
        self.cell(0, 8, f"- {self.page_no()} -", align="C")

    def h1(self, text: str):
        self.set_font("msyh", "", 18)
        self.set_text_color(25, 55, 95)
        self.multi_cell(0, 10, text)
        self.ln(2)

    def h2(self, text: str):
        self.set_font("msyh", "", 13)
        self.set_text_color(35, 75, 120)
        self.multi_cell(0, 8, text)
        self.ln(1)

    def h3(self, text: str):
        self.set_font("msyh", "", 11)
        self.set_text_color(50, 50, 50)
        self.multi_cell(0, 7, text)
        self.ln(1)

    def body(self, text: str, size: int = 11):
        self.set_font("msyh", "", size)
        self.set_text_color(30, 30, 30)
        self.multi_cell(0, 6.5, text)
        self.ln(1)

    def quote(self, text: str):
        x = self.get_x()
        y = self.get_y()
        self.set_fill_color(245, 248, 252)
        self.set_font("msyh", "", 11)
        self.set_text_color(40, 40, 40)
        self.set_x(x + 4)
        w = self.w - self.l_margin - self.r_margin - 8
        self.multi_cell(w, 6.5, text, fill=True)
        self.ln(2)
        self.set_x(x)

    def bullet(self, text: str):
        self.set_font("msyh", "", 11)
        self.set_text_color(30, 30, 30)
        self.multi_cell(0, 6.5, f"  •  {text}")
        self.ln(0.5)

    def divider(self):
        self.ln(2)
        self.set_draw_color(210, 210, 210)
        self.line(self.l_margin, self.get_y(), self.w - self.r_margin, self.get_y())
        self.ln(4)


def build_a_pdf() -> Path:
    pdf = SharePDF("周五分享 · A版口播稿")
    pdf.add_page()

    pdf.h1("周五分享口播稿（A版 · 问题解决型）")
    pdf.body("时长约 5 分钟  |  建议语速：正常偏慢，留停顿给同事消化")
    pdf.divider()

    pdf.h2("【开场 · 20 秒】")
    pdf.quote(
        "大家好，我分享一下这两个月的工作。我不打算讲具体做了哪些页面，"
        "主要讲我解决了三类问题：系统怎么更稳、数据怎么更准、操作怎么更顺。"
        "大家日常用系统应该都有感觉。"
    )

    pdf.h2("【第一点 · 发布更稳 · 约 1 分钟】")
    pdf.body("第一个是发布。")
    pdf.body(
        "以前我们改完代码，很多时候要靠手动上传，容易漏文件，或者线上版本和本地对不上。"
        "出了问题也不容易查是哪次更新引起的。"
    )
    pdf.body(
        "这两个月我把运行环境整理到统一服务器，并建立了「提交代码就自动上线」的流程。"
        "现在改完、push 上去，服务器会自动更新，不用每次等人手传文件。"
    )
    pdf.quote("大家能感受到的就是：线上版本更一致，更新也更快。")

    pdf.h2("【第二点 · 数据更准 · 约 1.5 分钟】")
    pdf.body("第二个是数据归属，这也是我花时间最多的一块。")
    pdf.body(
        "以前有个常见问题：切换集团或公司之后，有些画面数据没有跟着变。"
        "更严重的时候，会出现 A 公司的账目出现在 B 公司，或者 Games 和 Bank 的公司混在一起，"
        "点错就被踢回首页。"
    )
    pdf.body(
        "我做的不是「加一个新按钮」，而是把规则理顺：切换公司之后，所有相关数据都要跟着刷新，"
        "而且只显示那家公司的范围。另外也把 Games 和 Bank 的可选范围分开，"
        "避免大家在错误的业务里点到不相关的公司。"
    )
    pdf.quote("总结一句：切换公司之后，看到的应该就是那家公司的数据，不会串。")

    pdf.h2("【第三点 · 体验更顺 · 约 1 分钟】")
    pdf.body(
        "第三个是速度和稳定性。用户反馈过加载慢、切换时报错、或者按钮连点会重复提交。"
        "我针对这几类问题做了优化：加快慢的地方、把老旧模块逐步换成更稳的架构，"
        "也给关键保存操作加了防重复点击。"
    )
    pdf.quote("大家能感受到的就是：打开更快、切换更顺，少遇到「点两次存了两笔」这种情况。")

    pdf.h2("【演示 · 约 30 秒 · 有投影可做】")
    pdf.bullet("切换公司，数据会跟着变")
    pdf.bullet("Games 里不会出现 Bank 的公司")
    pdf.bullet("保存按钮连点，不会重复提交")

    pdf.h2("【收尾 · 20 秒】")
    pdf.body("最后总结三句话：")
    pdf.bullet("发布更稳 — 更新有流程，不靠手工")
    pdf.bullet("数据更准 — 切换公司不会串数据")
    pdf.bullet("体验更顺 — 更快、更少 Bug、更少误操作")
    pdf.ln(1)
    pdf.quote(
        "接下来我会继续把剩余场景统一到同一套规则里。"
        "如果大家碰到「切换公司后数据不对」这类问题，欢迎随时找我。谢谢。"
    )

    out = OUT_DIR / "周五分享-A版-问题解决型口播稿.pdf"
    pdf.output(str(out))
    return out


def build_c_pdf() -> Path:
    pdf = SharePDF("工作方法分享 · C版")
    pdf.add_page()

    pdf.h1("工作方法分享（C版 · 一页版）")
    pdf.body("适合转发给同事  |  不讲具体项目，只讲做事方法")
    pdf.divider()

    pdf.h2("1. 接需求：先搞清楚用户在烦什么")
    pdf.bullet("先按用户步骤复现问题，能重现再改")
    pdf.bullet("写清楚：哪个场景、期望什么、实际什么")
    pdf.quote("原则：先对准问题，再写代码")

    pdf.h2("2. 拆任务：大功能切成小块")
    pdf.bullet("一次只改一块 → 好排查、好回滚、好协作")
    pdf.body("大任务拆解示例：")
    pdf.bullet("定规则 → 单点试点 → 修 bug → 再铺开")
    pdf.bullet("先找最慢的地方 → 改一块 → 测一块")
    pdf.bullet("先手动跑通 → 再自动化")

    pdf.h2("3. 测试：改完必走的 checklist")
    pdf.body("切换类：Group / Company 切换后数据对不对？刷新后还对吗？")
    pdf.body("提交类：点一次一笔？连点不重复？慢网络时按钮有保护吗？")
    pdf.body("边界：没数据、没权限时页面是否正常？")
    pdf.quote("自己先测一轮，再交给别人或上线")

    pdf.h2("4. 团队协作：并行不打架")
    pdf.body("要更新代码 → 先 pull，解决冲突再 push")
    pdf.body("和别人改到同一块 → 看 diff，理解后再合并")
    pdf.body("可能影响别人 → 群里说一声动了哪块")
    pdf.quote("小步提交 + 及时沟通")

    pdf.h2("5. 工具怎么用")
    pdf.bullet("Git：频繁 commit，出问题能回退")
    pdf.bullet("AI 辅助：查代码、写重复逻辑；逻辑和测试自己把关")
    pdf.bullet("本地 build：改完先确认能打包再 push")
    pdf.bullet("记问题：反馈、截图、复现步骤记下来")

    pdf.h2("6. 心得（浓缩版）")
    pdf.bullet("先定义问题，再写代码")
    pdf.bullet("小步快跑，比一次大改稳")
    pdf.bullet("自测清单，比「我觉得没问题」靠谱")
    pdf.ln(2)
    pdf.body("有需要可以一起讨论，互相借鉴。", size=10)

    out = OUT_DIR / "周五分享-C版-工作方法一页版.pdf"
    pdf.output(str(out))
    return out


if __name__ == "__main__":
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    a = build_a_pdf()
    c = build_c_pdf()
    print(f"Generated:\n  {a}\n  {c}")
