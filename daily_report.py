#!/usr/bin/env python3
"""日次レポート統合スクリプト: GA4+GSCをSlackチャンネルに直接投稿"""

import os
import sys
import tempfile
from datetime import datetime, timedelta

from dotenv import load_dotenv

load_dotenv(os.path.join(os.path.dirname(__file__), ".env"))

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import matplotlib.dates as mdates
from matplotlib import font_manager

for _candidate in ("Hiragino Sans", "VL PGothic", "VL Gothic", "Noto Sans CJK JP", "IPAexGothic"):
    if any(f.name == _candidate for f in font_manager.fontManager.ttflist):
        matplotlib.rcParams["font.family"] = _candidate
        break

from google.oauth2 import service_account
from googleapiclient.discovery import build
from slack_sdk import WebClient

# --- 共通設定 ---
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
KEY_FILE = os.path.join(BASE_DIR, "config", "google-credentials.json")

SLACK_BOT_TOKEN = os.environ.get("SLACK_BOT_TOKEN", "")
SLACK_CHANNEL = os.environ.get("SLACK_CHANNEL", "")

GA4_PROPERTY_ID = "531092211"
GSC_SITE_URL = "https://oripanews.com/"


# ============================================================
# Slack
# ============================================================

def get_slack_client():
    return WebClient(token=SLACK_BOT_TOKEN)


def post_header(client, today):
    """日付ヘッダーをチャンネルに投稿する"""
    title = f"オリパ速報｜{today.strftime('%m/%d')}"
    client.chat_postMessage(channel=SLACK_CHANNEL, text=title)


def post_image(client, image_path, filename, comment):
    """チャンネルに画像付きメッセージを投稿する"""
    client.files_upload_v2(
        channel=SLACK_CHANNEL,
        file=image_path,
        filename=filename,
        initial_comment=comment,
        request_file_info=False,
    )


# ============================================================
# GA4
# ============================================================

def ga4_client():
    credentials = service_account.Credentials.from_service_account_file(
        KEY_FILE, scopes=["https://www.googleapis.com/auth/analytics.readonly"]
    )
    return build("analyticsdata", "v1beta", credentials=credentials)


def ga4_fetch_daily(client, date_range):
    body = {
        "dateRanges": [date_range],
        "dimensions": [{"name": "date"}],
        "metrics": [
            {"name": "averageSessionDuration"},
            {"name": "screenPageViewsPerSession"},
            {"name": "sessions"},
        ],
        "orderBys": [{"dimension": {"dimensionName": "date"}}],
    }
    response = client.properties().runReport(
        property=f"properties/{GA4_PROPERTY_ID}", body=body
    ).execute()
    return response.get("rows", [])


def ga4_rows_to_dict(rows):
    data = {}
    for row in rows:
        date = row["dimensionValues"][0]["value"]
        data[date] = {
            "avg_duration": float(row["metricValues"][0]["value"]),
            "pages_per_session": float(row["metricValues"][1]["value"]),
            "sessions": int(row["metricValues"][2]["value"]),
        }
    return data


def ga4_summarize(data):
    total_sessions = sum(d["sessions"] for d in data.values())
    if total_sessions == 0:
        return 0, 0, 0
    weighted_dur = sum(d["avg_duration"] * d["sessions"] for d in data.values()) / total_sessions
    weighted_pps = sum(d["pages_per_session"] * d["sessions"] for d in data.values()) / total_sessions
    return weighted_dur, weighted_pps, total_sessions


def fmt_duration(seconds):
    m, s = divmod(int(seconds), 60)
    return f"{m}:{s:02d}"


def ga4_create_chart(all_dates, data):
    empty = {"avg_duration": 0, "pages_per_session": 0, "sessions": 0}

    dates = [datetime.strptime(d, "%Y%m%d") for d in all_dates]
    durations = [data.get(d, empty)["avg_duration"] / 60 for d in all_dates]
    pps = [data.get(d, empty)["pages_per_session"] for d in all_dates]
    sessions = [data.get(d, empty)["sessions"] for d in all_dates]

    period = f"{all_dates[0][:4]}/{all_dates[0][4:6]}/{all_dates[0][6:]} 〜 {all_dates[-1][:4]}/{all_dates[-1][4:6]}/{all_dates[-1][6:]}"
    fig, axes = plt.subplots(3, 1, figsize=(14, 10), sharex=True)
    fig.suptitle(f"oripanews.com GA4日次推移\n{period}", fontsize=14, fontweight="bold")

    axes[0].plot(dates, durations, "o-", color="#e74c3c", markersize=4, linewidth=1.5)
    axes[0].set_ylabel("平均滞在時間 (分)")
    axes[0].grid(True, alpha=0.3)

    axes[1].plot(dates, pps, "o-", color="#3498db", markersize=4, linewidth=1.5)
    axes[1].set_ylabel("ページ/セッション")
    axes[1].grid(True, alpha=0.3)

    axes[2].bar(dates, sessions, color="#2ecc71", alpha=0.7, width=0.8)
    axes[2].set_ylabel("セッション数")
    axes[2].grid(True, alpha=0.3)

    axes[2].xaxis.set_major_formatter(mdates.DateFormatter("%m/%d"))
    axes[2].xaxis.set_major_locator(mdates.WeekdayLocator(interval=1))
    plt.xticks(rotation=45)
    plt.tight_layout()

    path = os.path.join(tempfile.gettempdir(), "oripa_ga4_daily_report.png")
    fig.savefig(path, dpi=150, bbox_inches="tight")
    plt.close(fig)
    return path


def run_ga4():
    """GA4データ取得→グラフ生成→コメント文とパスを返す"""
    client = ga4_client()
    date_range = {"startDate": "90daysAgo", "endDate": "yesterday"}

    print("[GA4] データ取得中...")
    data = ga4_rows_to_dict(ga4_fetch_daily(client, date_range))
    all_dates = sorted(data.keys())
    if not all_dates:
        print("[GA4] データなし")
        return None, None

    print(f"[GA4] 取得日数: {len(all_dates)}日")
    image_path = ga4_create_chart(all_dates, data)

    dur, pps, sess = ga4_summarize(data)
    period = f"{all_dates[0][:4]}/{all_dates[0][4:6]}/{all_dates[0][6:]} 〜 {all_dates[-1][:4]}/{all_dates[-1][4:6]}/{all_dates[-1][6:]}"
    comment = (
        f"*📊 GA4* ({period})\n"
        f"セッション: {sess:,} / 滞在: {fmt_duration(dur)} / ページ/S: {pps:.2f}"
    )
    return image_path, comment


# ============================================================
# GSC
# ============================================================

def gsc_service():
    credentials = service_account.Credentials.from_service_account_file(
        KEY_FILE, scopes=["https://www.googleapis.com/auth/webmasters.readonly"]
    )
    return build("searchconsole", "v1", credentials=credentials)


def gsc_fetch_daily():
    service = gsc_service()
    end_date = datetime.now() - timedelta(days=3)
    start_date = end_date - timedelta(days=89)

    body = {
        "startDate": start_date.strftime("%Y-%m-%d"),
        "endDate": end_date.strftime("%Y-%m-%d"),
        "dimensions": ["date"],
        "rowLimit": 90,
    }
    response = service.searchanalytics().query(siteUrl=GSC_SITE_URL, body=body).execute()
    rows = response.get("rows", [])

    dates, clicks, impressions, positions = [], [], [], []
    for row in sorted(rows, key=lambda r: r["keys"][0]):
        dates.append(datetime.strptime(row["keys"][0], "%Y-%m-%d"))
        clicks.append(int(row["clicks"]))
        impressions.append(int(row["impressions"]))
        positions.append(row["position"])

    return dates, clicks, impressions, positions


def gsc_fetch_top_pages(dates):
    service = gsc_service()
    body = {
        "startDate": dates[0].strftime("%Y-%m-%d"),
        "endDate": dates[-1].strftime("%Y-%m-%d"),
        "dimensions": ["page"],
        "rowLimit": 5,
    }
    response = service.searchanalytics().query(siteUrl=GSC_SITE_URL, body=body).execute()
    rows = response.get("rows", [])
    if len(rows) < 5:
        return None
    rows.sort(key=lambda r: r["clicks"], reverse=True)
    return rows[:5]


def gsc_create_chart(dates, clicks, impressions, positions):
    fig, (ax1, ax2, ax3) = plt.subplots(3, 1, figsize=(12, 8), sharex=True)
    fig.suptitle(
        f"oripanews.com GSC日次推移\n{dates[0].strftime('%Y/%m/%d')} 〜 {dates[-1].strftime('%Y/%m/%d')}",
        fontsize=14, fontweight="bold",
    )

    ax1.fill_between(dates, impressions, alpha=0.3, color="#4285F4")
    ax1.plot(dates, impressions, color="#4285F4", linewidth=1.2)
    ax1.set_ylabel("表示回数")
    ax1.grid(True, alpha=0.3)

    ax2.fill_between(dates, clicks, alpha=0.3, color="#34A853")
    ax2.plot(dates, clicks, color="#34A853", linewidth=1.2)
    ax2.set_ylabel("クリック数")
    ax2.grid(True, alpha=0.3)

    ax3.plot(dates, positions, color="#EA4335", linewidth=1.2)
    ax3.invert_yaxis()
    ax3.set_ylabel("平均順位")
    ax3.grid(True, alpha=0.3)

    ax3.xaxis.set_major_locator(mdates.WeekdayLocator(byweekday=mdates.MO))
    ax3.xaxis.set_major_formatter(mdates.DateFormatter("%m/%d"))
    plt.xticks(rotation=45)
    plt.tight_layout()

    path = os.path.join(tempfile.gettempdir(), "oripa_gsc_daily_report.png")
    fig.savefig(path, dpi=150, bbox_inches="tight")
    plt.close(fig)
    return path


def run_gsc():
    """GSCデータ取得→グラフ生成→コメント文とパスを返す"""
    print("[GSC] データ取得中...")
    dates, clicks, impressions, positions = gsc_fetch_daily()
    if not dates:
        print("[GSC] データなし")
        return None, None

    print(f"[GSC] 取得日数: {len(dates)}日")
    image_path = gsc_create_chart(dates, clicks, impressions, positions)

    period = f"{dates[0].strftime('%Y/%m/%d')} 〜 {dates[-1].strftime('%Y/%m/%d')}"
    total_clicks = sum(clicks)
    total_imp = sum(impressions)
    comment = f"*🔍 GSC* ({period})\n表示: {total_imp:,} / クリック: {total_clicks:,}"

    top_pages = gsc_fetch_top_pages(dates)
    if top_pages:
        comment += "\n\n*クリック上位5ページ*"
        for i, row in enumerate(top_pages, 1):
            url = row["keys"][0]
            c = int(row["clicks"])
            imp = int(row["impressions"])
            pos = f"{row['position']:.1f}"
            comment += f"\n{i}. {url}\n    clicks: {c} / imp: {imp:,} / pos: {pos}"

    return image_path, comment


# ============================================================
# メイン
# ============================================================

def main():
    if not SLACK_BOT_TOKEN or not SLACK_CHANNEL:
        print("SLACK_BOT_TOKEN / SLACK_CHANNEL が未設定。")
        sys.exit(1)

    today = datetime.now()
    slack = get_slack_client()

    # 1) 日付ヘッダー
    post_header(slack, today)
    print(f"ヘッダー投稿: オリパ速報｜{today.strftime('%m/%d')}")

    # 2) GA4 レポート → チャンネルに直接投稿
    ga4_image, ga4_comment = run_ga4()
    if ga4_image:
        post_image(slack, ga4_image, "oripa_ga4_daily_report.png", ga4_comment)
        print("[GA4] Slack投稿完了")

    # 3) GSC レポート → チャンネルに直接投稿
    gsc_image, gsc_comment = run_gsc()
    if gsc_image:
        post_image(slack, gsc_image, "oripa_gsc_daily_report.png", gsc_comment)
        print("[GSC] Slack投稿完了")

    print("完了")


if __name__ == "__main__":
    main()
