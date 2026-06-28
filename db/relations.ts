import { relations } from "drizzle-orm";
import { localUsers, roles, loads, drivers, shippers } from "./schema";

export const rolesRelations = relations(roles, ({ many }) => ({
  users: many(localUsers),
}));

export const localUsersRelations = relations(localUsers, ({ one }) => ({
  role: one(roles, {
    fields: [localUsers.roleId],
    references: [roles.id],
  }),
}));

export const shippersRelations = relations(shippers, ({ many }) => ({
  loads: many(loads),
}));

export const driversRelations = relations(drivers, ({ many }) => ({
  vehicles: many(drivers),
}));
