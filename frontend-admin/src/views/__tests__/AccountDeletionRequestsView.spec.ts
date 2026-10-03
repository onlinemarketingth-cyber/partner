/**
 * MOB-12 (2026-10-02) — คำขอลบบัญชี.
 *
 * Pins what the admin must be able to rely on before pressing an
 * irreversible button:
 *   * every row shows the three warning counts the API computed, money
 *     formatted from satang only at display;
 *   * approve asks a danger ConfirmDialog that names the person, says it
 *     cannot be undone and repeats the counts — nothing is sent until the
 *     dialog's confirm, and the ADR-052 saved dialog follows the re-read;
 *   * reject sends the typed note, also behind a ConfirmDialog;
 *   * a list that could not be read says so instead of "nothing to review",
 *     and a refused decision keeps the server's sentence on screen.
 */
import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";

const get = vi.fn();
const post = vi.fn();

const { FakeApiError } = vi.hoisted(() => ({
  FakeApiError: class extends Error {
    constructor(
      public status: number,
      public body: unknown,
      message?: string,
    ) {
      super(message ?? `API error ${status}`);
    }
  },
}));

vi.mock("@/api/client", () => ({
  api: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
    postForm: vi.fn(),
    download: vi.fn(),
  },
  ApiError: FakeApiError,
}));

vi.mock("vue-router", async () => {
  const actual =
    await vi.importActual<typeof import("vue-router")>("vue-router");

  return {
    ...actual,
    useRoute: () => ({ query: {} }),
    useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  };
});

import AccountDeletionRequestsView from "../AccountDeletionRequestsView.vue";
import { useAuthStore } from "@/stores/auth";
import { useActiveCompanyStore } from "@/stores/activeCompany";
import { saveFeedbackState } from "@/composables/useSaveFeedback";

function request(over: Record<string, unknown> = {}) {
  return {
    id: 41,
    status: "pending",
    reason: "เลิกทำธุรกิจแล้ว",
    requested_at: "2026-10-01T03:00:00Z",
    decided_at: null,
    decision_note: null,
    decided_by: null,
    company: { id: 5, name: "GENESENN" },
    user: {
      id: 7,
      name: "สมชาย ใจดี",
      email: "somchai@example.test",
      phone: "0812345678",
    },
    pending_commission_satang: 123450,
    downline_count: 2,
    client_count: 3,
    resolution: null,
    forfeited_commission_satang: null,
    ...over,
  };
}

let queue: unknown[] = [];
let failRead = false;

async function mountView() {
  const wrapper = mount(AccountDeletionRequestsView, {
    global: {
      stubs: {
        HeroHeader: { template: "<div><slot /></div>" },
        EmptyState: {
          props: ["title"],
          template: '<div data-test="empty">{{ title }}</div>',
        },
        Icon: true,
        LoadingSkeleton: true,
        CompanyScopeNotice: true,
        InfoPopover: { template: "<span><slot /></span>" },
      },
    },
  });
  await flushPromises();

  return wrapper;
}

type Wrapper = Awaited<ReturnType<typeof mountView>>;

function openDialog(w: Wrapper) {
  return w
    .findAllComponents({ name: "ConfirmDialog" })
    .find((d) => d.props("show") === true);
}

async function answerDialog(w: Wrapper, answer: "confirm" | "cancel") {
  const dialog = openDialog(w);
  expect(dialog, "an open ConfirmDialog").toBeDefined();
  const buttons = dialog!.findAll("button");
  await (
    answer === "confirm" ? buttons[buttons.length - 1] : buttons[0]
  )!.trigger("click");
  await flushPromises();
}

beforeEach(() => {
  get.mockReset();
  post.mockReset();
  queue = [request()];
  failRead = false;
  get.mockImplementation(async (path: string) => {
    if (String(path).startsWith("/companies")) return { data: [] };
    if (failRead) throw new FakeApiError(500, null);

    return { data: queue, meta: { last_page: 1 } };
  });
  useAuthStore().user = {
    id: 1,
    name: "ผู้ดูแลบริษัท",
    role: "company_admin",
  } as never;
  useActiveCompanyStore().setCompany(null);
});

describe("AccountDeletionRequestsView — list", () => {
  it("reads the pending queue and shows the agent, reason, date and the three counts", async () => {
    const wrapper = await mountView();

    expect(get).toHaveBeenCalledWith(
      expect.stringContaining("/account-deletion-requests?status=pending"),
    );
    const row = wrapper.get('[data-test="request-row"]');
    expect(row.text()).toContain("สมชาย ใจดี");
    expect(row.text()).toContain("somchai@example.test");
    expect(row.text()).toContain("เหตุผล: เลิกทำธุรกิจแล้ว");
    expect(row.text()).toContain("ขอลบเมื่อ");
    // 123450 satang → 1,234.50 บาท — divided by 100 only here.
    expect(row.get('[data-test="warning-commission"]').text()).toContain(
      "1,234.50 บาท",
    );
    expect(row.get('[data-test="warning-downline"]').text()).toContain(
      "ลูกทีมสายตรง 2 คน",
    );
    expect(row.get('[data-test="warning-clients"]').text()).toContain(
      "ลูกค้าที่ดูแล 3 ราย",
    );
  });

  it("switches status through the tabs", async () => {
    const wrapper = await mountView();
    get.mockClear();

    await wrapper.get('[data-test="tab-approved"]').trigger("click");
    await flushPromises();

    expect(get).toHaveBeenCalledWith(
      expect.stringContaining("/account-deletion-requests?status=approved"),
    );
  });

  it("says the list could not be read instead of claiming there is nothing to review", async () => {
    failRead = true;
    const wrapper = await mountView();

    expect(wrapper.get('[data-test="load-error"]').text()).toContain(
      "โหลดคำขอลบบัญชีไม่สำเร็จ (500)",
    );
    expect(wrapper.find('[data-test="empty"]').exists()).toBe(false);

    failRead = false;
    await wrapper.get('[data-test="load-error"] button').trigger("click");
    await flushPromises();
    expect(wrapper.find('[data-test="request-row"]').exists()).toBe(true);
  });

  it("shows the empty state when nothing is waiting", async () => {
    queue = [];
    const wrapper = await mountView();

    expect(wrapper.get('[data-test="empty"]').text()).toContain(
      "ไม่มีคำขอลบบัญชีที่รอพิจารณา",
    );
  });
});

describe("AccountDeletionRequestsView — approve", () => {
  it("asks a danger dialog naming the person, saying it is irreversible and listing the counts; sends nothing yet", async () => {
    const wrapper = await mountView();

    await wrapper.get('[data-test="approve"]').trigger("click");
    await flushPromises();

    expect(post).not.toHaveBeenCalled();
    const dialog = openDialog(wrapper)!;
    expect(dialog.props("variant")).toBe("danger");
    const body = dialog.props("body") as string;
    expect(body).toContain('"สมชาย ใจดี"');
    expect(body).toContain("ย้อนกลับไม่ได้");
    expect(body).toContain("ค่าแนะนำค้างจ่าย 1,234.50 บาท");
    expect(body).toContain("ลูกทีมสายตรง 2 คน");
    expect(body).toContain("ลูกค้าที่ดูแล 3 ราย");
  });

  it("cancel sends nothing", async () => {
    const wrapper = await mountView();

    await wrapper.get('[data-test="approve"]').trigger("click");
    await answerDialog(wrapper, "cancel");

    expect(post).not.toHaveBeenCalled();
    expect(openDialog(wrapper)).toBeUndefined();
    expect(saveFeedbackState.show).toBe(false);
  });

  it("confirm calls the endpoint, re-reads the queue, then raises the saved dialog", async () => {
    post.mockImplementation(async () => {
      queue = [];

      return {
        data: request({
          status: "approved",
          user: {
            id: 7,
            name: "บัญชีที่ถูกลบ #7",
            email: "deleted-7@deleted.invalid",
            phone: null,
          },
        }),
      };
    });
    const wrapper = await mountView();

    await wrapper.get('[data-test="approve"]').trigger("click");
    await answerDialog(wrapper, "confirm");

    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith("/account-deletion-requests/41/approve");
    expect(saveFeedbackState.show).toBe(true);
    // The name the admin saw, not the anonymised placeholder the server now holds.
    expect(saveFeedbackState.body).toContain("สมชาย ใจดี");
    expect(wrapper.find('[data-test="request-row"]').exists()).toBe(false);
  });

  it("a refusal keeps the server's sentence on screen and raises no saved dialog", async () => {
    post.mockRejectedValue(
      new FakeApiError(409, null, "คำขอนี้ไม่ได้อยู่ในสถานะรอพิจารณาแล้ว"),
    );
    const wrapper = await mountView();

    await wrapper.get('[data-test="approve"]').trigger("click");
    await answerDialog(wrapper, "confirm");

    expect(saveFeedbackState.show).toBe(false);
    expect(openDialog(wrapper)).toBeUndefined();
    expect(wrapper.get('[data-test="decision-error"]').text()).toContain(
      "คำขอนี้ไม่ได้อยู่ในสถานะรอพิจารณาแล้ว",
    );
  });
});

describe("AccountDeletionRequestsView — reject", () => {
  it("sends the typed note only after the dialog confirms", async () => {
    post.mockImplementation(async () => {
      queue = [];

      return {
        data: request({
          status: "rejected",
          decision_note: "ยังมียอดค้างจ่าย",
        }),
      };
    });
    const wrapper = await mountView();

    await wrapper.get('[data-test="reject"]').trigger("click");
    await wrapper.get('[data-test="reject-note"]').setValue("ยังมียอดค้างจ่าย");
    await wrapper.get('[data-test="submit-reject"]').trigger("click");
    await flushPromises();

    expect(post).not.toHaveBeenCalled();
    const dialog = openDialog(wrapper)!;
    expect(dialog.props("body")).toContain('"สมชาย ใจดี"');
    expect(dialog.props("body")).toContain("หมายเหตุ: ยังมียอดค้างจ่าย");

    await answerDialog(wrapper, "confirm");

    expect(post).toHaveBeenCalledWith("/account-deletion-requests/41/reject", {
      note: "ยังมียอดค้างจ่าย",
    });
    expect(saveFeedbackState.show).toBe(true);
    expect(saveFeedbackState.body).toContain(
      "ปฏิเสธคำขอลบบัญชีของ สมชาย ใจดี แล้ว",
    );
  });

  it("sends no note when none was typed", async () => {
    post.mockResolvedValue({ data: request({ status: "rejected" }) });
    const wrapper = await mountView();

    await wrapper.get('[data-test="reject"]').trigger("click");
    await wrapper.get('[data-test="submit-reject"]').trigger("click");
    await answerDialog(wrapper, "confirm");

    expect(post).toHaveBeenCalledWith("/account-deletion-requests/41/reject", {
      note: undefined,
    });
  });
});

/*
 * MOB-12 follow-up (owner decision 2026-10-03) — the approved tab must tell an
 * immediate deletion (nothing owed, or waived) apart from one an admin
 * approved, and quote what was waived from satang.
 */
describe("AccountDeletionRequestsView — how a decided request was closed", () => {
  const decided = {
    status: "approved",
    decided_at: "2026-10-03T04:00:00Z",
    user: {
      id: 7,
      name: "บัญชีที่ถูกลบ #7",
      email: "deleted-7@deleted.invalid",
      phone: null,
    },
    pending_commission_satang: 0,
    downline_count: 0,
    client_count: 0,
  };

  async function lineFor(over: Record<string, unknown>) {
    queue = [request({ ...decided, ...over })];
    const wrapper = await mountView();
    await wrapper.get('[data-test="tab-approved"]').trigger("click");
    await flushPromises();

    return wrapper.get('[data-test="decision-line"]').text();
  }

  it("immediate with a waiver quotes the forfeited amount", async () => {
    const line = await lineFor({
      resolution: "immediate",
      forfeited_commission_satang: 15450,
    });

    expect(line).toContain("ลบทันที (สละค่าคอม 154.50 บาท)");
  });

  it("immediate with nothing owed says so", async () => {
    const line = await lineFor({
      resolution: "immediate",
      forfeited_commission_satang: null,
    });

    expect(line).toContain("ลบทันที (ไม่มีค่าคอมค้าง)");
  });

  it("an admin approval names the admin", async () => {
    const line = await lineFor({
      resolution: "admin",
      decided_by: { id: 1, name: "ผู้ดูแลบริษัท" },
    });

    expect(line).toContain("อนุมัติโดยผู้ดูแล ผู้ดูแลบริษัท");
  });
});
