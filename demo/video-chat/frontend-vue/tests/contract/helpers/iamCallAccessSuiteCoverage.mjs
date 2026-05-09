import { CALL_ACCESS_E2E_SPECS, callAccessE2eCommandText } from '../../e2e/call-access-e2e-suite.mjs';
import {
  IAM_CALL_ACCESS_CONTRACT_COMMANDS,
  iamCallAccessContractCommandText,
} from '../iam-call-access-contract-suite.mjs';

export const callAccessE2eSuiteText = callAccessE2eCommandText();
export const iamCallAccessContractSuiteText = iamCallAccessContractCommandText();
export const callAccessE2eSpecs = CALL_ACCESS_E2E_SPECS;
export const iamCallAccessContractCommands = IAM_CALL_ACCESS_CONTRACT_COMMANDS;
